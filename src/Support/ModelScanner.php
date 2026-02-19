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
                $class = $this->getClassFromFile($file->getRealPath());

                if ($class === null) {
                    continue;
                }

                if (in_array($class, $this->excludedModels, true)) {
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
        } catch (\ReflectionException $e) {
            return false;
        }
    }
}
