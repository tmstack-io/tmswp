// E2E(tests/E2E/)の設定。対象はexamples/sample-siteのwp-env(ポート8882)で、起動とseedはsample-siteの
// `composer wp:start`・`composer wp:setup`で行う(webServerは使わない。D-55)。実行は`composer test:e2e`。

import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/E2E',
    outputDir: './var/e2e/test-results',
    // テストはサイトの状態(設定・メタ・記録したメール)を読み書きするため、1つずつ順に走らせる
    workers: 1,
    fullyParallel: false,
    retries: 0,
    forbidOnly: !!process.env.CI,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: 'list',
    use: {
        baseURL: 'http://localhost:8882',
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
