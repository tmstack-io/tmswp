#!/usr/bin/env bash
#
# ACF Proの上で、acf-bridgeの統合テスト(Pro専用のグループacf-proを含む)を走らせる(docs/09 §14・docs/10)。
#
#   TMSWP_ACF_PRO_ZIP=/path/to/advanced-custom-fields-pro.zip composer test:integration:acf-pro [-- <phpunitの引数>]
#
# - リポジトリの外に、実行ごとに一意の作業ディレクトリ(${TMPDIR}/tmswp-acf-pro.XXXXXXXX)を作り、zipの展開・wp-envの
#   設定ファイル(wp-env --config)・WP_ENV_HOMEを置く。本体のwp-env(.wp-env.json)とは、設定ファイル・WP_ENV_HOME・
#   ポート(8898)が別の、隔離した環境で走らせる。コンテナもDBも共有しない。
# - wp-envのプロジェクト名(コンテナ等の名前)は、設定ファイルのパスから決まるため、実行ごとに一意になる。同じ道具を
#   同時に走らせても、互いの環境を消さない(ポートが同じため、後から起動した方は起動に失敗する)。
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
# - Proは無料版のスラッグ(advanced-custom-fields)に置くため、fixtureの有効化(tests/fixtures/_activate.php)はそのまま
#   使える。
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

wpenv_bin="${repo}/node_modules/@wordpress/env/bin/wp-env"

# shellcheck source=tools/lib/isolated-wp-env.sh
source "${repo}/tools/lib/isolated-wp-env.sh"
work="$(isolated_wp_env_create_work tmswp-acf-pro)"
config="${work}/tmswp-acf-pro/wp-env.json"
plugin="${work}/plugin"
export WP_ENV_HOME="${work}/wp-env-home"
project="$(isolated_wp_env_project "$config")" || {
    rm -rf "$work"
    fail "cannot derive the wp-env project name from ${config}"
}
isolated_wp_env_remove_on_exit "$wpenv_bin" "$work" "$config" "$project"
echo "acf-pro-integration: work ${work}, project ${project}"

mkdir -p "$plugin" "$WP_ENV_HOME"
unzip -q "$zip" 'advanced-custom-fields-pro/*' -d "$plugin"

# 本体の.wp-env.jsonと同じWordPress・PHPの版で、プラグインはマウントだけで載せる設定を書く
# shellcheck disable=SC2016 # ${...}はJavaScriptのテンプレート文字列で、シェルに展開させない
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

# 作る環境の資源がまだ無いことを確かめ、この実行のものとして引き受ける(ここから後は、Dockerの資源も終了時に消す)
isolated_wp_env_claim "$project"

echo "acf-pro-integration: ACF Pro ${version} (${zip})"

wpenv=(node "$wpenv_bin" "--config=${config}")
cd "${config%/*}"

"${wpenv[@]}" start
isolated_wp_env_assert_started "$project"

"${wpenv[@]}" run cli --env-cwd=wp-content/plugins/tmswp \
    vendor-bin/wp-tests/vendor/bin/phpunit -c phpunit.integration.xml.dist \
    --testsuite acf-bridge --exclude-group acf-free "$@"
