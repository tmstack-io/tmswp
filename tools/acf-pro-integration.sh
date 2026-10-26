#!/usr/bin/env bash
#
# ACF Proの上で、acf-bridgeの統合テスト(Pro専用のグループacf-proを含む)を走らせる(docs/09 §14・docs/10)。
#
#   TMSWP_ACF_PRO_ZIP=/path/to/advanced-custom-fields-pro.zip composer test:integration:acf-pro [-- <phpunitの引数>]
#
# - zipは、リポジトリの外の作業ディレクトリ(${TMPDIR}/tmswp-acf-pro)に展開し、終了時に必ず消す。
# - 本体のwp-env(.wp-env.json)とは、別の設定ファイル(wp-env --config)とポートで隔離した環境で走らせる。
#   コンテナもDBも共有しない。Proは無料版のスラッグ(advanced-custom-fields)に置くため、fixtureの有効化
#   (tests/fixtures/_activate.php)はそのまま使える。
# - 無料版であることを確かめるテスト(グループacf-free)は外す。
# - ライセンスキーは使わない。

set -euo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() {
    echo "acf-pro-integration: $*" >&2
    exit 1
}

zip="${TMSWP_ACF_PRO_ZIP:-}"

[ -n "$zip" ] || fail 'TMSWP_ACF_PRO_ZIP is not set; set it to the path of an ACF Pro zip (advanced-custom-fields-pro.zip)'
[ -f "$zip" ] || fail "TMSWP_ACF_PRO_ZIP is not a file: ${zip}"
[ -r "$zip" ] || fail "TMSWP_ACF_PRO_ZIP is not readable: ${zip}"

# zipの中のプラグインの本体ファイルのヘッダから版を読む。読めなければACF Proのzipではない
version="$(unzip -p "$zip" advanced-custom-fields-pro/acf.php 2>/dev/null | sed -n 's/^ \* Version: *//p' | head -n 1 || true)"
[ -n "$version" ] || fail "${zip} is not an ACF Pro zip: advanced-custom-fields-pro/acf.php with a Version header was not found"

work="${TMPDIR:-/tmp}"
work="${work%/}/tmswp-acf-pro"
plugin="${work}/plugin"
config="${work}/wp-env.json"

cleanup() {
    rm -rf "$plugin"
}
trap cleanup EXIT

rm -rf "$plugin"
mkdir -p "$plugin"
unzip -q "$zip" 'advanced-custom-fields-pro/*' -d "$plugin"

# 本体の.wp-env.jsonと同じWordPress・PHPの版で、プラグインはマウントだけで載せる設定を書く
node -e '
const fs = require("fs");
const [repo, pro, out] = process.argv.slice(1);
const base = JSON.parse(fs.readFileSync(`${repo}/.wp-env.json`, "utf8"));
const config = {
    core: base.core,
    phpVersion: base.phpVersion,
    testsEnvironment: false,
    port: 8898,
    plugins: [],
    mappings: {
        "wp-content/plugins/tmswp": repo,
        "wp-content/plugins/advanced-custom-fields": pro,
    },
    config: base.config,
};
fs.writeFileSync(out, JSON.stringify(config, null, 2) + "\n");
' "$repo" "${plugin}/advanced-custom-fields-pro" "$config"

echo "acf-pro-integration: ACF Pro ${version} (${zip})"

wpenv=(node "${repo}/node_modules/@wordpress/env/bin/wp-env" "--config=${config}")
cd "$work"

"${wpenv[@]}" start

status=0
"${wpenv[@]}" run cli --env-cwd=wp-content/plugins/tmswp \
    vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist \
    --testsuite acf-bridge --exclude-group acf-free "$@" || status=$?

"${wpenv[@]}" stop || {
    echo 'acf-pro-integration: wp-env stop failed' >&2
    [ "$status" -ne 0 ] || status=1
}

exit "$status"
