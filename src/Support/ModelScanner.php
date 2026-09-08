<?php

namespace Devlin\ModelAnalyzer\Support;

use Symfony\Component\Finder\Finder;
use Illuminate\Database\Eloquent\Model;

class ModelScanner
{
    /** @var array */
    private $paths;

    /** @var array */
    private $excludedModels;

    /**
     * Models skipped because their dependencies could not be resolved.
     *
     * @var string[]
     */
    private $warnings = [];

    /**
     * @param array $paths
     * @param array $excludedModels
     */
    public function __construct(array $paths, array $excludedModels = [])
    {
        $this->paths          = $paths;
        $this->excludedModels = $excludedModels;
    }

    /**
     * Scan all configured paths and return fully-qualified class names
     * of every non-abstract Eloquent model found.
     *
     * @return string[]
     */
    public function scan()
    {
        $models         = [];
        $this->warnings = [];

        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->name('*.php')->in($path);

            foreach ($finder as $file) {
                $class = $this->getClassFromFile($file->getRealPath());

                if ($class === null) {
                    continue;
                }

                if (in_array($class, $this->excludedModels, true)) {
                    continue;
                }

                // Resolve the class's traits, parent and interfaces WITHOUT
                // loading it. Linking a class whose trait or parent is missing
                // is an uncatchable fatal error, so this check must happen
                // before class_exists() triggers autoloading.
                $missing = $this->unresolvableDependencies($file->getRealPath());

                if (count($missing) > 0) {
                    $this->warnings[] = sprintf(
                        'Skipped %s: unresolved %s (%s). Fix the imports in %s.',
                        $class,
                        count($missing) === 1 ? 'dependency' : 'dependencies',
                        implode(', ', $missing),
                        basename($file->getRealPath())
                    );

                    continue;
                }

                if (!$this->isEloquentModel($class)) {
                    continue;
                }

                $models[] = $class;
            }
        }

        return array_unique($models);
    }

    /**
     * Parse a PHP file and return its fully-qualified class name, or null.
     *
     * @param string $filePath
     * @return string|null
     */
    protected function getClassFromFile($filePath)
    {
        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $tokens    = token_get_all($contents);
        $count     = count($tokens);
        $namespace = '';
        $class     = '';

        for ($i = 0; $i < $count; $i++) {
            // Collect namespace
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                $ns = '';
                $i++;
                while ($i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i])) {
                        $ns .= $tokens[$i][1];
                    } else {
                        $ns .= $tokens[$i];
                    }
                    $i++;
                }
                $namespace = trim($ns);
            }

            // Collect class name (skip abstract classes)
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                // Check if the previous meaningful token is "abstract"
                $j = $i - 1;
                while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j--;
                }
                if ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_ABSTRACT) {
                    continue;
                }

                $i++;
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                    $class = $tokens[$i][1];
                    break;
                }
            }
        }

        if ($class === '') {
            return null;
        }

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }

    /**
     * Warnings for models skipped during the last scan().
     *
     * @return string[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

    /**
     * Names this file depends on (traits, parent class, interfaces) that do
     * not resolve to anything loadable.
     *
     * Checking each dependency by name is safe: a missing symbol simply fails
     * to autoload and the *_exists() call returns false. It is linking the
     * dependent class that would fatal, which is exactly what this prevents.
     *
     * Note the limit: if a parent class itself has a broken dependency, this
     * check resolves the parent's NAME successfully and the fatal moves one
     * level up. Deep chains of broken classes are not fully protected.
     *
     * @param  string $filePath
     * @return string[] Unresolved names, as written in the source
     */
    protected function unresolvableDependencies($filePath)
    {
        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            return [];
        }

        $parsed = $this->parseDependencies($contents);
        $missing = [];

        foreach ($parsed['traits'] as $name) {
            $fq = $this->resolveName($name, $parsed['namespace'], $parsed['imports']);

            if (!trait_exists($fq)) {
                $missing[] = $name;
            }
        }

        foreach ($parsed['parents'] as $name) {
            $fq = $this->resolveName($name, $parsed['namespace'], $parsed['imports']);

            if (!class_exists($fq) && !interface_exists($fq)) {
                $missing[] = $name;
            }
        }

        foreach ($parsed['interfaces'] as $name) {
            $fq = $this->resolveName($name, $parsed['namespace'], $parsed['imports']);

            if (!interface_exists($fq) && !class_exists($fq)) {
                $missing[] = $name;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Tokenize a source file into its namespace, import table, trait uses,
     * parent class and interfaces.
     *
     * @param  string $contents
     * @return array{namespace: string, imports: array, traits: string[], parents: string[], interfaces: string[]}
     */
    protected function parseDependencies($contents)
    {
        $tokens = token_get_all($contents);
        $count  = count($tokens);

        $namespace  = '';
        $imports    = [];
        $traits     = [];
        $parents    = [];
        $interfaces = [];

        $depth      = 0;   // brace depth; class bodies sit at depth >= 1
        $inClass    = false;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                continue;
            }

            switch ($token[0]) {
                case T_NAMESPACE:
                    $namespace = $this->readName($tokens, $i + 1, $count);
                    break;

                case T_USE:
                    if ($depth === 0) {
                        // Top-level import. May be a group or comma list.
                        $this->readImports($tokens, $i + 1, $count, $imports);
                    } elseif ($inClass) {
                        // Trait use inside a class body.
                        foreach ($this->readNameList($tokens, $i + 1, $count) as $name) {
                            $traits[] = $name;
                        }
                    }
                    break;

                case T_CLASS:
                    // Ignore ::class constant references.
                    if ($i >= 2 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON) {
                        break;
                    }

                    $inClass = true;
                    break;

                case T_EXTENDS:
                    foreach ($this->readNameList($tokens, $i + 1, $count) as $name) {
                        $parents[] = $name;
                    }
                    break;

                case T_IMPLEMENTS:
                    foreach ($this->readNameList($tokens, $i + 1, $count) as $name) {
                        $interfaces[] = $name;
                    }
                    break;
            }
        }

        return [
            'namespace'  => $namespace,
            'imports'    => $imports,
            'traits'     => $traits,
            'parents'    => $parents,
            'interfaces' => $interfaces,
        ];
    }

    /**
     * Read a single qualified name starting at $start.
     *
     * @param  array $tokens
     * @param  int   $start
     * @param  int   $count
     * @return string
     */
    private function readName(array $tokens, $start, $count)
    {
        $name = '';

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ';' || $token === '{' || $token === ',' || $token === '(') {
                    break;
                }
                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                if ($name !== '') {
                    break;
                }
                continue;
            }

            if ($this->isNameToken($token[0])) {
                $name .= $token[1];
                continue;
            }

            break;
        }

        return trim($name, '\\');
    }

    /**
     * Read a comma-separated list of qualified names (extends/implements/use).
     *
     * @param  array $tokens
     * @param  int   $start
     * @param  int   $count
     * @return string[]
     */
    private function readNameList(array $tokens, $start, $count)
    {
        $names   = [];
        $current = '';

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ',') {
                    if ($current !== '') {
                        $names[] = trim($current, '\\');
                        $current = '';
                    }
                    continue;
                }

                // '{' ends `use Trait {` conflict blocks and class headers.
                if ($token === ';' || $token === '{' || $token === '(') {
                    break;
                }

                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            if ($this->isNameToken($token[0])) {
                $current .= $token[1];
                continue;
            }

            // T_IMPLEMENTS after an extends list, or anything else, ends it.
            break;
        }

        if ($current !== '') {
            $names[] = trim($current, '\\');
        }

        return $names;
    }

    /**
     * Read one or more top-level `use` imports into the alias table.
     *
     * Handles `use A\B;`, `use A\B as C;` and comma lists. Group imports
     * (`use A\{B, C};`) and function/const imports are skipped rather than
     * mis-resolved.
     *
     * @param  array $tokens
     * @param  int   $start
     * @param  int   $count
     * @param  array $imports
     * @return void
     */
    private function readImports(array $tokens, $start, $count, array &$imports)
    {
        $current = '';
        $alias   = null;
        $isAlias = false;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ',' || $token === ';') {
                    if ($current !== '') {
                        $this->addImport($imports, $current, $alias);
                    }

                    $current = '';
                    $alias   = null;
                    $isAlias = false;

                    if ($token === ';') {
                        return;
                    }

                    continue;
                }

                if ($token === '{') {
                    // Group use — skip it entirely.
                    return;
                }

                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            if ($token[0] === T_AS) {
                $isAlias = true;
                continue;
            }

            if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
                return;
            }

            if ($this->isNameToken($token[0])) {
                if ($isAlias) {
                    $alias = $token[1];
                } else {
                    $current .= $token[1];
                }
                continue;
            }

            break;
        }

        if ($current !== '') {
            $this->addImport($imports, $current, $alias);
        }
    }

    /**
     * @param  array       $imports
     * @param  string      $fqName
     * @param  string|null $alias
     * @return void
     */
    private function addImport(array &$imports, $fqName, $alias)
    {
        $fqName = trim($fqName, '\\');

        if ($fqName === '') {
            return;
        }

        if ($alias === null) {
            $parts = explode('\\', $fqName);
            $alias = end($parts);
        }

        $imports[$alias] = $fqName;
    }

    /**
     * Resolve a source-level name to a fully-qualified one.
     *
     * @param  string $name
     * @param  string $namespace
     * @param  array  $imports
     * @return string
     */
    private function resolveName($name, $namespace, array $imports)
    {
        $name = ltrim($name, '\\');

        if ($name === '') {
            return $name;
        }

        $parts = explode('\\', $name);
        $first = $parts[0];

        if (isset($imports[$first])) {
            if (count($parts) === 1) {
                return $imports[$first];
            }

            return $imports[$first] . '\\' . implode('\\', array_slice($parts, 1));
        }

        // Unimported names resolve against the current namespace.
        return $namespace !== '' ? $namespace . '\\' . $name : $name;
    }

    /**
     * PHP 8 emits qualified names as single tokens; PHP 7 splits them.
     *
     * @param  int $type
     * @return bool
     */
    private function isNameToken($type)
    {
        if ($type === T_STRING || $type === T_NS_SEPARATOR) {
            return true;
        }

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant) && $type === constant($constant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the given class is a non-abstract Eloquent model.
     *
     * @param string $class
     * @return bool
     */
    protected function isEloquentModel($class)
    {
        if (!class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                return false;
            }

            return $reflection->isSubclassOf(Model::class);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
