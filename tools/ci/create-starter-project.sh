#!/usr/bin/env bash
# スターター(starters/theme・starters/plugin)から、composer create-projectでプロジェクトを作る(CIのStarter Projectsジョブ)。
#
# 使い方:
#   tools/ci/create-starter-project.sh <theme|plugin> <作る先のディレクトリ> [<スラッグ> <表示名>]
#
# スラッグと表示名を渡すと、post-create-project-cmd(bin/setup.php)を非対話で実行し、作ったプロジェクトに`{{`が
# 残っていないこと、プラグインの本体ファイルが`<スラッグ>.php`になり`plugin.php`が無いことを確かめる。
# 渡さないと、--no-scriptsでスターターをそのまま写す(コミット済みのビルドの出力と探索結果ファイルを照合するため)。
#
# スターターとtmswp/coreは、このmonorepoのディレクトリをpathリポジトリ(写し。シンボリックリンクにしない)で入れる。
# 版はtmswp/coreのKernel::VERSIONにする(Kernelは、入っているtmswp/coreの版とKernel::VERSIONが違うと起動しない)。
# それ以外の依存はPackagistから入れる。
set -euo pipefail

if [ "$#" -ne 2 ] && [ "$#" -ne 4 ]; then
    echo "usage: $0 <theme|plugin> <directory> [<slug> <name>]" >&2
    exit 2
fi

kind=$1
directory=$2
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

case "$kind" in
    theme | plugin) ;;
    *)
        echo "unknown starter: ${kind} (theme or plugin)" >&2
        exit 2
        ;;
esac

if [ -e "$directory" ]; then
    echo "${directory} already exists" >&2
    exit 1
fi

kernel="$root/packages/core/src/Kernel/Kernel.php"
version=$(sed -nE "s/^ *public const string VERSION = '([^']+)';$/\1/p" "$kernel")

if [ -z "$version" ]; then
    echo "cannot find Kernel::VERSION in ${kernel}" >&2
    exit 1
fi

repository() {
    printf '{"type": "path", "url": "%s", "options": {"symlink": false, "versions": {"%s": "%s"}}}' "$1" "$2" "$version"
}

arguments=(
    create-project --no-interaction --no-progress --add-repository
    --repository="$(repository "$root/starters/$kind" "tmswp/starter-$kind")"
    --repository="$(repository "$root/packages/core" tmswp/core)"
)

if [ "$#" -eq 2 ]; then
    composer "${arguments[@]}" --no-scripts "tmswp/starter-$kind" "$directory" "$version"
    exit 0
fi

slug=$3
TMSWP_SETUP_SLUG=$slug TMSWP_SETUP_NAME=$4 composer "${arguments[@]}" "tmswp/starter-$kind" "$directory" "$version" </dev/null

# grepの終了値は、0が一致あり、1が一致なし、それ以外が検査の失敗(読めないファイル等)。失敗を一致なしとして扱わない
placeholders=0
grep -rn "{{" "$directory" --exclude-dir=vendor --exclude-dir=node_modules || placeholders=$?

case "$placeholders" in
    0)
        echo "::error::${directory} still has {{ placeholders"
        exit 1
        ;;
    1) ;;
    *)
        echo "::error::cannot check ${directory} for {{ placeholders (grep exited with ${placeholders})"
        exit 1
        ;;
esac

if [ -e "$directory/bin/setup.php" ]; then
    echo "::error::${directory}/bin/setup.php was not removed"
    exit 1
fi

if [ "$kind" = plugin ]; then
    if [ ! -f "$directory/$slug.php" ] || [ -e "$directory/plugin.php" ]; then
        echo "::error::the plugin file of ${directory} is not renamed to ${slug}.php"
        exit 1
    fi
fi

echo "created ${kind} ${slug} in ${directory} (tmswp/core ${version})"
