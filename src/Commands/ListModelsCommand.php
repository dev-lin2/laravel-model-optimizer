<?php

namespace Devlin\ModelAnalyzer\Commands;

use Illuminate\Console\Command;
use Devlin\ModelAnalyzer\Support\ModelScanner;

class ListModelsCommand extends Command
{
    /** @var string */
    protected $signature = 'model-analyzer:list-models
                            {--with-relationships : Include relationship counts per model}
                            {--json              : Output as JSON}';

    /** @var string */
    protected $description = 'List all discovered Eloquent models';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $config  = config('model-analyzer');
        $scanner = new ModelScanner(
            $config['model_paths'],
            $config['excluded_models']
        );

        $classes = $scanner->scan();

        if (count($classes) === 0) {
            $this->warn('No Eloquent models found in the configured paths.');
            return 0;
        }

        if ($this->option('json')) {
            $this->line(json_encode(array_values($classes), JSON_PRETTY_PRINT));
            return 0;
        }

        $withRels = $this->option('with-relationships');
        $rows     = [];

        foreach ($classes as $class) {
            try {
                /** @var \Illuminate\Database\Eloquent\Model $instance */
                $instance = new $class();
                $table    = $instance->getTable();
            } catch (\Throwable $e) {
                $table = '(unknown)';
            }

            $row = [class_basename($class), $class, $table];

            if ($withRels) {
                $relCount = $this->countRelationships($class);
                $row[]    = $relCount;
            }

            $rows[] = $row;
        }

        $headers = ['Model', 'Class', 'Table'];
        if ($withRels) {
            $headers[] = 'Relationships';
        }

        $this->line(sprintf('<info>Found %d model(s):</info>', count($classes)));
        $this->table($headers, $rows);

        return 0;
    }

    /**
     * Count public zero-arg methods that return an Eloquent Relation on the given model.
     *
     * @param string $class
     * @return int
     */
    private function countRelationships($class)
    {
        try {
            $reflection = new \ReflectionClass($class);
            $count      = 0;

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if ($method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                try {
                    $instance = new $class();
                    $result   = $method->invoke($instance);
                    if ($result instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
