<?php

namespace Devlin\ModelAnalyzer\Tests\Unit;

use Devlin\ModelAnalyzer\Support\ModelScanner;
use Devlin\ModelAnalyzer\Tests\TestCase;

/**
 * A model whose trait, parent or interface cannot be resolved raises an
 * UNCATCHABLE fatal error when PHP links the class, so try/catch cannot
 * protect against it. The scanner must therefore detect the problem
 * statically and skip the class before triggering autoload.
 */
class ModelScannerResilienceTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var callable|null */
    private $autoloader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ma-models-' . uniqid();
        mkdir($this->dir, 0777, true);

        // The real-world failure only happens when the broken class IS
        // autoloadable: class_exists() then links it and PHP fatals. Without
        // an autoloader the lookup simply returns false and proves nothing.
        $dir = $this->dir;

        $this->autoloader = function ($class) use ($dir) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $file  = $dir . '/' . $short . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        };

        spl_autoload_register($this->autoloader);
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
            $this->autoloader = null;
        }

        foreach (glob($this->dir . '/*.php') as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param string $name
     * @param string $body
     * @return void
     */
    private function model($name, $body)
    {
        file_put_contents($this->dir . '/' . $name . '.php', "<?php\n" . $body);
    }

    public function test_a_model_using_a_missing_trait_is_skipped_without_a_fatal_error()
    {
        // Mirrors the real-world failure: `use Notifiable;` with no matching
        // import, so it resolves to App\Notifiable, which does not exist.
        $this->model('BrokenAdmin', <<<'PHP'
namespace MaFixtureBroken;

use Illuminate\Database\Eloquent\Model;

class BrokenAdmin extends Model
{
    use Notifiable;

    protected $guard = 'admin';
}
PHP
        );

        $scanner = new ModelScanner([$this->dir]);
        $models  = $scanner->scan();

        $this->assertSame([], $models);
        $this->assertNotEmpty($scanner->getWarnings());
        $this->assertStringContainsString('Notifiable', $scanner->getWarnings()[0]);
    }

    public function test_a_model_extending_a_missing_parent_is_skipped()
    {
        $this->model('OrphanChild', <<<'PHP'
namespace MaFixtureBroken;

class OrphanChild extends NotARealBaseClass
{
}
PHP
        );

        $scanner = new ModelScanner([$this->dir]);

        $this->assertSame([], $scanner->scan());
        $this->assertNotEmpty($scanner->getWarnings());
    }

    public function test_a_model_implementing_a_missing_interface_is_skipped()
    {
        $this->model('BadContract', <<<'PHP'
namespace MaFixtureBroken;

use Illuminate\Database\Eloquent\Model;

class BadContract extends Model implements TotallyMissingContract
{
}
PHP
        );

        $scanner = new ModelScanner([$this->dir]);

        $this->assertSame([], $scanner->scan());
        $this->assertNotEmpty($scanner->getWarnings());
    }

    public function test_a_healthy_model_is_still_discovered()
    {
        $this->model('GoodModel', <<<'PHP'
namespace MaFixtureHealthy;

use Illuminate\Database\Eloquent\Model;

class GoodModel extends Model
{
    protected $table = 'good_models';
}
PHP
        );

        $scanner = new ModelScanner([$this->dir]);

        $this->assertSame(['MaFixtureHealthy\GoodModel'], $scanner->scan());
        $this->assertSame([], $scanner->getWarnings());
    }

    public function test_an_imported_trait_that_does_exist_is_accepted()
    {
        $this->model('SoftModel', <<<'PHP'
namespace MaFixtureHealthy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftModel extends Model
{
    use SoftDeletes;
}
PHP
        );

        $scanner = new ModelScanner([$this->dir]);

        $this->assertSame(['MaFixtureHealthy\SoftModel'], $scanner->scan());
        $this->assertSame([], $scanner->getWarnings());
    }

    public function test_an_aliased_trait_import_is_resolved()
    {
        $this->model('AliasModel', <<<'PHP'
namespace MaFixtureHealthy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes as Trashable;

class AliasModel extends Model
{
    use Trashable;
}
PHP
        );

        $scanner = new ModelScanner([$this->dir]);

        $this->assertSame(['MaFixtureHealthy\AliasModel'], $scanner->scan());
        $this->assertSame([], $scanner->getWarnings());
    }
}
