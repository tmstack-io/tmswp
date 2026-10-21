<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use RuntimeException as PhpRuntimeException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\JtiClaim;
use TMSWP\Core\Form\JtiStore;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\BootsKernelBeforeInit;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\Tests\Support\RecordingLogger;
use TMSWP\Core\Tests\Support\UsesTempDirectories;

/**
 * フォームの二重送信の防止(実際の nonce と、wp_options への jti の claim)、onComplete の例外の扱い、
 * ページキャッシュからの除外を、実物の WordPress で確かめる。
 */
final class FormIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string JTI = '0123456789abcdef0123456789abcdef';

    private RecordingLogger $logger;

    /** @var list<array<string, mixed>> */
    private array $completed = [];

    /** onComplete に例外を投げさせるか */
    private bool $failOnComplete = false;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
        $this->completed = [];
        $this->failOnComplete = false;
    }

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testReloadAndDoubleClickOfFormWithoutConfirmationRunOnCompleteOnce(): void
    {
        $form = $this->formFactory(debug: false);
        $definition = $this->contactDefinition();
        $post = $this->submitRequest($form($definition)->open(), ['name' => 'Taro']);

        self::assertSame(FormStep::Complete, $form($definition)->handle($post)->step);

        foreach (['reload', 'double click'] as $case) {
            $result = $form($definition)->handle($post);

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['This form has already been submitted.'], $result->formErrors, $case);
        }

        self::assertSame([['name' => 'Taro']], $this->completed);
        self::assertSame(
            ['notice', 'notice'],
            array_map(static fn(array $record): mixed => $record['level'], $this->logger->records),
        );
    }

    public function testResubmittingTheConfirmTokenRunsOnCompleteOnce(): void
    {
        $form = $this->formFactory(debug: false);
        $definition = $this->contactDefinition()->confirm();
        $confirm = $form($definition);
        $confirm->handle($this->confirmRequest($form($definition)->open(), ['name' => 'Taro']));
        $submit = $this->submitRequest($confirm->open());

        self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        self::assertSame(['This form has already been submitted.'], $form($definition)->handle($submit)->formErrors);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testConfirmTokenValuesThatNoLongerPassTheRulesAreRejectedWithoutConsumingTheJti(): void
    {
        $form = $this->formFactory(debug: false);
        $before = $this->contactDefinition()->confirm();
        $submit = $this->submitRequestFor($form, $before, ['name' => 'Taro Yamada']);
        // 確認の後のデプロイで MaxLength を足した
        $after = FormDefinition::create('contact')
            ->text('name', 'Name', [new Required(), new MaxLength(5)])
            ->confirm()
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });

        $result = $form($after)->handle($submit);

        self::assertSame(FormStep::Input, $result->step);
        self::assertArrayHasKey('name', $result->fieldErrors);
        self::assertSame([], $this->completed);
        // jti を claim していない(wp_options に行が無い)
        $wpdb = self::wpdb();
        self::assertNull($wpdb->get_var($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('tmswp_form_jti_') . '%')));

        self::assertSame(FormStep::Complete, $form($before)->handle($submit)->step);
        self::assertSame([['name' => 'Taro Yamada']], $this->completed);
    }

    public function testOnCompleteExceptionInProductionIsLoggedAndTheSameTokenCanBeResubmitted(): void
    {
        $form = $this->formFactory(debug: false);

        foreach ([false, true] as $confirmEnabled) {
            $this->logger->records = [];
            $definition = $this->contactDefinition()->confirm($confirmEnabled);
            $submit = $this->submitRequestFor($form, $definition, ['name' => 'Taro']);
            $this->failOnComplete = true;

            $failed = $form($definition)->handle($submit);

            self::assertSame(FormStep::Input, $failed->step);
            self::assertSame(['The form could not be sent. Please try again.'], $failed->formErrors);
            self::assertSame('Taro', $failed->values['name']);
            self::assertCount(1, $this->logger->records);
            self::assertSame('error', $this->logger->records[0]['level']);
            self::assertSame('tmswp.form: onComplete of form {form} threw an exception', $this->logger->records[0]['message']);
            self::assertSame(PhpRuntimeException::class, $this->logger->records[0]['context']['exceptionClass'] ?? null);
            self::assertArrayNotHasKey('exception', $this->logger->records[0]['context']);

            // jti は消費されていないので、同じトークンで再送できる
            $this->failOnComplete = false;

            self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        }

        self::assertSame([['name' => 'Taro'], ['name' => 'Taro']], $this->completed);
    }

    public function testOnCompleteExceptionInDebugIsThrownAndTheSameTokenCanBeResubmitted(): void
    {
        $form = $this->formFactory(debug: true);
        $definition = $this->contactDefinition();
        $submit = $this->submitRequestFor($form, $definition, ['name' => 'Taro']);
        $this->failOnComplete = true;

        try {
            $form($definition)->handle($submit);
            self::fail('the exception of onComplete was not thrown');
        } catch (PhpRuntimeException $exception) {
            self::assertSame('mail server is down', $exception->getMessage());
        }

        $this->failOnComplete = false;

        self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testJtiStoreClaimsReleasesAndCollectsOnTheRealDatabase(): void
    {
        $store = new JtiStore();
        $wpdb = self::wpdb();

        self::assertSame(JtiClaim::Claimed, $store->claim(self::JTI, 60));
        self::assertSame(JtiClaim::Duplicate, $store->claim(self::JTI, 60));
        // alloptions のキャッシュを汚さない
        self::assertSame('off', $wpdb->get_var($wpdb->prepare('SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'tmswp_form_jti_' . self::JTI)));

        $store->release(self::JTI);

        self::assertSame(JtiClaim::Claimed, $store->claim(self::JTI, 60));

        // 期限切れの jti だけを消す
        self::assertSame(JtiClaim::Claimed, $store->claim('fedcba9876543210fedcba9876543210', -10));
        self::assertGreaterThanOrEqual(1, $store->gc());
        self::assertSame(JtiClaim::Duplicate, $store->claim(self::JTI, 60));
        self::assertSame(JtiClaim::Claimed, $store->claim('fedcba9876543210fedcba9876543210', 60));
    }

    public function testJtiStoreDatabaseErrorIsAnException(): void
    {
        $wpdb = self::wpdb();
        $options = $wpdb->options;
        $suppress = $wpdb->suppress_errors(true);
        // 存在しないテーブルへのクエリで、データベースのエラーを起こす
        $wpdb->options = $wpdb->prefix . 'tmswp_no_such_table';

        try {
            (new JtiStore())->claim(self::JTI, 60);
            self::fail('RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('failed to claim the form submission ID: ', $exception->getMessage());
            self::assertStringContainsString('tmswp_no_such_table', $exception->getMessage());
        } finally {
            $wpdb->options = $options;
            $wpdb->suppress_errors($suppress);
        }
    }

    /**
     * PHP の CLI はヘッダを送らないため、wp-env の開発用サイトへ、フォームのあるページ(tests/fixtures/http/form-page.php)を
     * HTTP で取得して、nocache のヘッダと DONOTCACHEPAGE を確かめる。
     */
    public function testResponseOfPageWithFormHasNoCacheHeadersAndDoNotCachePage(): void
    {
        $body = file_get_contents('http://wordpress/wp-content/plugins/tmswp/tests/fixtures/http/form-page.php');

        self::assertIsString($body);
        self::assertStringContainsString('name="' . Form::FIELD_SUBMIT_TOKEN . '"', $body);

        $headers = [];

        foreach ($http_response_header as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }

        self::assertSame('no-cache, must-revalidate, max-age=0, no-store, private', $headers['cache-control'] ?? null);
        self::assertSame('Wed, 11 Jan 1984 05:00:00 GMT', $headers['expires'] ?? null);
        self::assertSame('true', $headers['x-tmswp-donotcachepage'] ?? null);
    }

    private function contactDefinition(): FormDefinition
    {
        return FormDefinition::create('contact')
            ->text('name', 'Name', [new Required()])
            ->onComplete(function (array $values): void {
                if ($this->failOnComplete) {
                    throw new PhpRuntimeException('mail server is down');
                }

                $this->completed[] = $values;
            });
    }

    /**
     * 一時ディレクトリをルートとするプラグインの Kernel を boot し、そのルートの中のファイルで `new Form()` を書いた
     * フォームを作る関数を返す(Form は作ったファイルで Kernel を決める)。
     *
     * @return Closure(FormDefinition): Form
     */
    private function formFactory(bool $debug): Closure
    {
        $root = $this->createTempDirectory('integration-form');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $logger = $this->logger;
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegrationForm' . uniqid(),
            $debug,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
        $this->writeFile($root . '/form-factory.php', <<<'PHP'
<?php

declare(strict_types=1);

return static fn(\TMSWP\Core\Form\FormDefinition $definition): \TMSWP\Core\Form\Form => new \TMSWP\Core\Form\Form($definition);
PHP);
        $factory = require $root . '/form-factory.php';
        self::assertInstanceOf(Closure::class, $factory);

        return static function (FormDefinition $definition) use ($factory): Form {
            $form = $factory($definition);
            self::assertInstanceOf(Form::class, $form);

            return $form;
        };
    }

    /**
     * 入力画面(確認画面ありなら、確認を通した後の確認画面)の送信の POST を作る。
     *
     * @param Closure(FormDefinition): Form $form
     * @param array<string, string> $values
     */
    private function submitRequestFor(Closure $form, FormDefinition $definition, array $values): FormRequest
    {
        if (!$definition->isConfirmEnabled()) {
            return $this->submitRequest($form($definition)->open(), $values);
        }

        $confirm = $form($definition);
        self::assertSame(FormStep::Confirm, $confirm->handle($this->confirmRequest($form($definition)->open(), $values))->step);

        return $this->submitRequest($confirm->open());
    }

    /** @param array<string, string> $values */
    private function confirmRequest(string $html, array $values): FormRequest
    {
        return new FormRequest('POST', [...$this->hiddenValues($html), Form::FIELD_STEP => 'confirm', ...$values]);
    }

    /** @param array<string, string> $values */
    private function submitRequest(string $html, array $values = []): FormRequest
    {
        return new FormRequest('POST', [...$this->hiddenValues($html), Form::FIELD_STEP => 'submit', ...$values]);
    }

    /** @return array<string, string> open() が出力した hidden の名前と値 */
    private function hiddenValues(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER);
        $values = [];

        foreach ($matches as $match) {
            $values[$match[1]] = html_entity_decode($match[2], ENT_QUOTES);
        }

        self::assertArrayHasKey(Form::FIELD_NONCE, $values);

        return $values;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}
