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
        $models = [];

        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->name('*.php')->in($path);

            foreach ($finder as $file) {
                $info = $this->parseFile($file->getRealPath());

                if ($info === null) {
                    continue;
                }

                $class = $info['class'];

                if (in_array($class, $this->excludedModels, true)) {
                    continue;
                }

                // Pre-filter by parent class name to avoid autoloading files
                // that are clearly not models. This protects against fatal
                // errors in broken controllers, middleware, etc., which in
                // PHP 7.4 cannot be caught via try/catch.
                if (!$this->looksLikeModel($info['extends'])) {
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
     * Parse a PHP file and return its class metadata (FQCN + parent class), or null.
     *
     * @param string $filePath
     * @return array|null
     */
    protected function parseFile($filePath)
    {
        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $tokens    = token_get_all($contents);
        $count     = count($tokens);
        $namespace = '';
        $class     = '';
        $extends   = '';

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

                    // Look ahead for `extends ParentClass`
                    $k = $i + 1;
                    while ($k < $count && $tokens[$k] !== '{') {
                        if (is_array($tokens[$k]) && $tokens[$k][0] === T_EXTENDS) {
                            $k++;
                            // Skip whitespace
                            while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                                $k++;
                            }
                            // Collect the extends identifier (possibly namespaced)
                            $parent = '';
                            while ($k < $count && is_array($tokens[$k])
                                && ($tokens[$k][0] === T_STRING || $tokens[$k][0] === T_NS_SEPARATOR)) {
                                $parent .= $tokens[$k][1];
                                $k++;
                            }
                            $extends = $parent;
                            break;
                        }
                        $k++;
                    }

                    break;
                }
            }
        }

        if ($class === '') {
            return null;
        }

        $fqcn = $namespace !== '' ? $namespace . '\\' . $class : $class;

        return ['class' => $fqcn, 'extends' => $extends];
    }

    /**
     * Determine if the parent class name (as written in the `extends` clause)
     * plausibly belongs to an Eloquent model. Unknown parents are treated as
     * "maybe" so custom base models still get scanned — only clearly non-model
     * bases (Controller, Middleware, ServiceProvider, etc.) are rejected.
     *
     * @param string $extends Short name or FQCN of the parent class.
     * @return bool
     */
    protected function looksLikeModel($extends)
    {
        if ($extends === '') {
            // No parent — not an Eloquent model.
            return false;
        }

        // Extract the short name from a possibly-namespaced identifier.
        $shortName = $extends;
        $pos = strrpos($extends, '\\');
        if ($pos !== false) {
            $shortName = substr($extends, $pos + 1);
        }

        // Clearly-not-model Laravel base classes.
        $nonModelBases = [
            'Controller',
            'Middleware',
            'ServiceProvider',
            'FormRequest',
            'Request',
            'Rule',
            'Command',
            'Notification',
            'Mailable',
            'Event',
            'Listener',
            'Policy',
            'Resource',
            'JsonResource',
            'ResourceCollection',
            'Seeder',
            'Factory',
            'Exception',
            'TestCase',
            'Job',
            'Channel',
            'Broadcast',
            'Observer',
            'Kernel',
            'RouteServiceProvider',
            'AuthServiceProvider',
            'EventServiceProvider',
            'AppServiceProvider',
            'BroadcastServiceProvider',
            'HttpKernel',
            'ConsoleKernel',
        ];

        if (in_array($shortName, $nonModelBases, true)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the given class is a non-abstract Eloquent model.
     *
     * @param string $class
     * @return bool
     */
    protected function isEloquentModel($class)
    {
        try {
            if (!class_exists($class)) {
                return false;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                return false;
            }

            return $reflection->isSubclassOf(Model::class);
        } catch (\Throwable $e) {
            // Skip files that fail to autoload (missing traits, parse errors,
            // broken use statements, etc.) so the scanner can continue.
            return false;
        }
    }
}
