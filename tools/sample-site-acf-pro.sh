#!/usr/bin/env bash
#
# sample-site(examples/sample-site)を、ACF Proを載せて隔離したwp-envで起動し、ACFの機能のE2E(tests/E2E/acf.spec.mjs。
# 製品の詳細の価格と仕様の表示と、管理画面での値の変更の反映)を走らせる(docs/12のv0.9.0-T1)。
#
#   TMSWP_ACF_PRO_ZIP=/path/to/advanced-custom-fields-pro.zip bash tools/sample-site-acf-pro.sh [<Playwrightの引数>]
#
# 前提: ルートで`npm ci`(@wordpress/envと@playwright/test)、Playwrightのchromium、examples/sample-siteで`composer install`。
#
# - リポジトリの外に、実行ごとに一意の作業ディレクトリ(${TMPDIR}/tmswp-sample-site-acf-pro.XXXXXXXX)を作り、Proの
#   展開・設定ファイル・WP_ENV_HOME・E2Eの写しを置く。wp-envのプロジェクト名(コンテナ等の名前)は、設定ファイルの
#   パスから決まるため、実行ごとに一意になる。同じ道具を同時に走らせても、互いの環境を消さない(ポートが同じため、
#   後から起動した方は起動に失敗する)。
# - 終了時(成功・失敗・SIGINT・SIGTERM)に、その実行の作業ディレクトリ(展開したProを含む)を消す。wp-envの環境
#   (コンテナ・ボリューム・ネットワーク・その環境のイメージ)は、起動の前に資源が無いことを確かめて引き受けた後だけ
#   消す。既に資源があれば、それに触れずに作業ディレクトリだけを消して止まる(tools/lib/isolated-wp-env.sh)。
# - SIGKILL等で片付けが走らなかったときは、実行の始めに出した作業ディレクトリ(work)とプロジェクト名(project)で、
#   手で消す。
#   <work>と<project>は、それぞれ引用符の中に置き換える(パスに空白があっても1つの引数になる)。
#     docker ps -aq --filter "label=com.docker.compose.project=<project>" | xargs docker rm --force --volumes
#     docker volume ls -q --filter "label=com.docker.compose.project=<project>" | xargs docker volume rm
#     docker network ls -q --filter "label=com.docker.compose.project=<project>" | xargs docker network rm
#     docker rmi "<project>-wordpress:latest" "<project>-cli:latest"
#     rm -rf -- "<work>"
# - 残るリスク: isolated_wp_env_claimで資源が無いことを確かめてからwp-envのstartまでの間に、同じプロジェクト名の環境が
#   作られると、その資源も片付けの対象になる(プロジェクト名はパスのMD5の先頭8桁を含み、重なるのはその衝突のときだけ)。
# - 設定ファイルは、examples/sample-site/.wp-env.jsonを写し、パスを絶対パスにし、`plugins`を`[]`にして、Proを
#   `mappings`で無料版のスラッグ(wp-content/plugins/advanced-custom-fields)に載せ、ポートを8897にしたもの。
#   wp-envには`--config`と別のWP_ENV_HOMEで渡す。sample-siteの専用の環境(ポート8882)とは、設定ファイル・WP_ENV_HOME・
#   ポート・プロジェクト名(コンテナの名前)が別で、その環境を起動・停止しない。examples/sample-site/のファイルは変えない
#   (テーマとしてマウントするだけ)。
# - E2Eは、tests/E2E/を変えずに作業ディレクトリへ写して走らせる。写しから見たnode_modules/@wordpress/env/bin/wp-envを、
#   この環境の設定ファイルとWP_ENV_HOMEを足して本物を呼ぶ中継に置き換え、baseURLは、ルートのplaywright.config.mjsを
#   読み込んで上書きした設定で変える。Playwrightには常にacf.spec.mjsを渡し、引数はその後に足す(ほかのspecの一部は
#   ポート8882を前提にするため、ここでは走らせない)。
# - ライセンスキーは使わない。

set -euo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
site="${repo}/examples/sample-site"
wpenv_bin="${repo}/node_modules/@wordpress/env/bin/wp-env"
playwright_cli="${repo}/node_modules/@playwright/test/cli.js"
port=8897

fail() {
    echo "sample-site-acf-pro: $*" >&2
    exit 1
}

zip="${TMSWP_ACF_PRO_ZIP:-}"

[ -n "$zip" ] || fail 'TMSWP_ACF_PRO_ZIP is not set; set it to the path of an ACF Pro zip (advanced-custom-fields-pro.zip)'
[ -f "$zip" ] || fail "TMSWP_ACF_PRO_ZIP is not a file: ${zip}"
[ -r "$zip" ] || fail "TMSWP_ACF_PRO_ZIP is not readable: ${zip}"
[ -f "$wpenv_bin" ] || fail "${wpenv_bin} is missing; run npm ci in ${repo}"
[ -f "$playwright_cli" ] || fail "${playwright_cli} is missing; run npm ci in ${repo}"
[ -f "${site}/vendor/autoload.php" ] || fail "${site}/vendor is missing; run composer install in ${site}"

# zipの中のプラグインの本体ファイルのヘッダから版を読む。読めなければACF Proのzipではない
version="$(unzip -p "$zip" advanced-custom-fields-pro/acf.php 2>/dev/null | sed -n 's/^ \* Version: *//p' | head -n 1 || true)"
[ -n "$version" ] || fail "${zip} is not an ACF Pro zip: advanced-custom-fields-pro/acf.php with a Version header was not found"

# shellcheck source=tools/lib/isolated-wp-env.sh
source "${repo}/tools/lib/isolated-wp-env.sh"
work="$(isolated_wp_env_create_work tmswp-sample-site-acf-pro)"
config="${work}/tmswp-sample-site-acf-pro/wp-env.json"
plugin="${work}/plugin"
e2e="${work}/e2e"
export WP_ENV_HOME="${work}/wp-env-home"
project="$(isolated_wp_env_project "$config")" || {
    rm -rf "$work"
    fail "cannot derive the wp-env project name from ${config}"
}
isolated_wp_env_remove_on_exit "$wpenv_bin" "$work" "$config" "$project"
echo "sample-site-acf-pro: work ${work}, project ${project}"
wpenv=(node "$wpenv_bin" "--config=${config}")

mkdir -p "$plugin" "$WP_ENV_HOME" "${e2e}/examples/sample-site" "${e2e}/node_modules/@wordpress/env/bin"
unzip -q "$zip" 'advanced-custom-fields-pro/*' -d "$plugin"

# examples/sample-site/.wp-env.jsonを写す。相対パスはsample-siteのディレクトリからの絶対パスにし、無料版のACFの
# プラグイン(plugins)の代わりに、Proを無料版のスラッグにマウントする
# shellcheck disable=SC2016 # ${...}はJavaScriptのテンプレート文字列で、シェルに展開させない
node -e '
const fs = require("fs");
const path = require("path");
const [site, pro, port, out] = process.argv.slice(1);
const base = JSON.parse(fs.readFileSync(path.join(site, ".wp-env.json"), "utf8"));
for (const source of base.plugins ?? []) {
    if (!/\/advanced-custom-fields\.[0-9.]+\.zip$/.test(source)) {
        throw new Error(`examples/sample-site/.wp-env.json has a plugin other than the free ACF: ${source}`);
    }
}
const resolve = (source) => path.resolve(site, source);
const mappings = Object.fromEntries(Object.entries(base.mappings ?? {}).map(([target, source]) => [target, resolve(source)]));
mappings["wp-content/plugins/advanced-custom-fields"] = pro;
const config = { ...base, port: Number(port), plugins: [], themes: (base.themes ?? []).map(resolve), mappings };
fs.writeFileSync(out, JSON.stringify(config, null, 2) + "\n");
' "$site" "${plugin}/advanced-custom-fields-pro" "$port" "$config"

# 作る環境の資源がまだ無いことを確かめ、この実行のものとして引き受ける(ここから後は、Dockerの資源も終了時に消す)
isolated_wp_env_claim "$project"

echo "sample-site-acf-pro: ACF Pro ${version} (${zip}), http://localhost:${port}"

cd "${config%/*}"
"${wpenv[@]}" start
isolated_wp_env_assert_started "$project"

# plugins: []のため、マウントしたACFは有効になっていない。Proであることを確かめてから、sample-siteのseed
# (examples/sample-siteのcomposer wp:setupと同じ)を行う
"${wpenv[@]}" run cli wp plugin activate advanced-custom-fields
edition="$("${wpenv[@]}" run cli wp eval 'echo acf_is_pro() ? "pro" : "free", " ", ACF_VERSION, "\n";' | grep -E '^(pro|free) ' | tail -n 1 || true)"
[ "$edition" = "pro ${version}" ] || fail "the active ACF is not ACF Pro ${version}: ${edition}"
"${wpenv[@]}" run cli wp theme activate sample-site
"${wpenv[@]}" run cli --env-cwd=wp-content/themes/sample-site wp eval "require 'dev/seed.php';"

# E2Eの写し。tests/E2E/support/wp-env.mjsは、写しの位置から3つ上をルートとみなして、そのnode_modulesのwp-envを
# examples/sample-siteをcwdにして呼ぶ
mkdir -p "${e2e}/tests"
cp -R "${repo}/tests/E2E" "${e2e}/tests/E2E"
ln -s "${repo}/node_modules/@playwright" "${e2e}/node_modules/@playwright"
# shellcheck disable=SC2016 # ${...}はJavaScriptのテンプレート文字列で、シェルに展開させない
node -e '
const fs = require("fs");
const [real, config, home, out] = process.argv.slice(1);
const shim = `#!/usr/bin/env node
"use strict";
// tools/sample-site-acf-pro.shが作った中継。隔離した環境の設定ファイルとWP_ENV_HOMEを足して、本物を呼ぶ
const { spawnSync } = require("node:child_process");
const result = spawnSync(process.execPath, [${JSON.stringify(real)}, ${JSON.stringify(`--config=${config}`)}, ...process.argv.slice(2)], {
    stdio: "inherit",
    env: { ...process.env, WP_ENV_HOME: ${JSON.stringify(home)} },
});
if (result.error) {
    throw result.error;
}
process.exit(result.status ?? 1);
`;
fs.writeFileSync(out, shim, { mode: 0o755 });
' "$wpenv_bin" "$config" "$WP_ENV_HOME" "${e2e}/node_modules/@wordpress/env/bin/wp-env"
cat > "${e2e}/playwright.config.mjs" << EOF
import base from '${repo}/playwright.config.mjs';

export default { ...base, testDir: './tests/E2E', outputDir: './test-results', use: { ...base.use, baseURL: 'http://localhost:${port}' } };
EOF

cd "$e2e"
node "$playwright_cli" test --config="${e2e}/playwright.config.mjs" acf.spec.mjs "$@"
