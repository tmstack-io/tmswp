#!/usr/bin/env bash
# packages/*・starters/*の8つを、split.ymlの同期と同じ形の一時のgitのリポジトリへ取り出し、配布物の中身と、
# ライブラリの単独のinstall・testを確かめる(CIのSplit Packages Checkのジョブ。docs/RELEASING.mdの「split相当の確認」)。
#
# 使い方:
#   tools/ci/split-check.sh
#
# 対象は、このリポジトリのHEADのコミットである(作業ツリーのコミットしていない変更は含まない)。パッケージごとに、
# HEADのそのディレクトリの中身だけを持つコミットを作って一時のリポジトリへ送り、split.ymlと同じく、正本の
# .github/split/close-pull-request.ymlを.github/workflows/close-pull-request.ymlに置いてコミットする。
# - packages/*の6つ: `git archive HEAD`に、tests/・phpunit.xml.dist・.gitattributes・.DS_Store・.github/が入らず、
#   composer.jsonとsrc/が入ることを確かめる。続けて、tmswp/core・tmswp/testingを、同じく取り出したcore・testingを指す
#   pathリポジトリ(写し。版はKernel::VERSION)で入れ、`composer install`と`composer test`を実行する。
# - starters/*の2つ: install・testは行わず、`git archive HEAD`に、.github/・.DS_Store・node_modules/が入らず、
#   作ったプロジェクトが使うtests/とphpunit.xml.distが入ることを確かめる。
# 一時のリポジトリは、終わったら消す。どれかの確認が失敗すれば、非0で終わる。
set -euo pipefail

if [ "$#" -ne 0 ]; then
    echo "usage: $0" >&2
    exit 2
fi

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
workflow="$root/.github/split/close-pull-request.yml"
version=$(sed -nE "s/^ *public const string VERSION = '([^']+)';$/\1/p" "$root/packages/core/src/Kernel/Kernel.php")

if [ -z "$version" ]; then
    echo "Kernel::VERSION is not found in packages/core/src/Kernel/Kernel.php" >&2
    exit 1
fi

if [ ! -f "$workflow" ]; then
    echo "${workflow} is not found" >&2
    exit 1
fi

work=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/tmswp-split-check.XXXXXX")
trap 'rm -rf "$work"' EXIT

# 一時のリポジトリのコミットの作者。利用者のgitの設定の署名は使わない
export GIT_AUTHOR_NAME='TMSWP split check' GIT_AUTHOR_EMAIL='split-check@example.invalid'
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME" GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
git_quiet() {
    git -c commit.gpgSign=false -c init.defaultBranch=main "$@"
}

libraries=(core thumbnail settings-page acf-bridge ja-toolkit testing)
starters=(theme plugin)
failed=0

# <monorepoのディレクトリ> <名前>: split相当の一時のリポジトリを$work/<名前>に作り、$work/<名前>.git(originのbare)へ送る
split_package() {
    local directory=$1 name=$2 tree commit
    tree=$(git -C "$root" rev-parse "HEAD:${directory}")
    commit=$(git_quiet -C "$root" commit-tree "$tree" -m "split ${directory}")
    git_quiet init --quiet --bare "$work/${name}.git"
    git_quiet -C "$root" push --quiet "$work/${name}.git" "${commit}:refs/heads/main"
    git_quiet clone --quiet --branch main "$work/${name}.git" "$work/${name}"
    mkdir -p "$work/${name}/.github/workflows"
    cp "$workflow" "$work/${name}/.github/workflows/close-pull-request.yml"
    git_quiet -C "$work/${name}" add .github/workflows/close-pull-request.yml
    git_quiet -C "$work/${name}" commit --quiet -m 'add the workflow that closes pull requests'
    git_quiet -C "$work/${name}" push --quiet origin main
}

# <名前>: `git archive HEAD`の一覧を$work/<名前>.archive-listに書く。gitかtarが失敗すれば、set -eとpipefailで止まる
list_archive() {
    local name=$1
    git -C "$work/${name}" archive HEAD | tar -t > "$work/${name}.archive-list"
}

# <名前> <拡張正規表現>: 一覧のうち正規表現に合う行を出す。grepの終了コードの1(一致なし)だけを成功にし、
# 2以上(読み取りや正規表現の誤り)は止める
matching_entries() {
    local name=$1 pattern=$2 status=0
    grep -E "$pattern" "$work/${name}.archive-list" || status=$?
    if [ "$status" -gt 1 ]; then
        echo "::error::${name}: cannot search the archive list (grep exited with ${status})" >&2
        exit 1
    fi
}

# <名前> <説明> <拡張正規表現>: `git archive HEAD`の一覧に、正規表現に合う行が無いことを確かめる
assert_excluded() {
    local name=$1 label=$2 pattern=$3 found
    found=$(matching_entries "$name" "$pattern")
    if [ -n "$found" ]; then
        echo "::error::${name}: the archive contains ${label}:"
        echo "$found"
        failed=1
    fi
}

# <名前> <説明> <拡張正規表現>: `git archive HEAD`の一覧に、正規表現に合う行があることを確かめる
assert_included() {
    local name=$1 label=$2 pattern=$3 found
    found=$(matching_entries "$name" "$pattern")
    if [ -z "$found" ]; then
        echo "::error::${name}: the archive does not contain ${label}"
        failed=1
    fi
}

for name in "${libraries[@]}"; do
    split_package "packages/${name}" "$name"
    list_archive "$name"
    assert_excluded "$name" 'tests/' '^tests(/|$)'
    assert_excluded "$name" 'phpunit.xml.dist' '^phpunit\.xml\.dist$'
    assert_excluded "$name" '.gitattributes' '^\.gitattributes$'
    assert_excluded "$name" '.DS_Store' '(^|/)\.DS_Store$'
    assert_excluded "$name" '.github/' '^\.github(/|$)'
    assert_included "$name" 'composer.json' '^composer\.json$'
    assert_included "$name" 'src/' '^src/.'
done

for name in "${starters[@]}"; do
    split_package "starters/${name}" "starter-${name}"
    list_archive "starter-${name}"
    assert_excluded "starter-${name}" '.github/' '^\.github(/|$)'
    assert_excluded "starter-${name}" '.DS_Store' '(^|/)\.DS_Store$'
    assert_excluded "starter-${name}" 'node_modules/' '^node_modules(/|$)'
    assert_included "starter-${name}" 'tests/' '^tests/.'
    assert_included "starter-${name}" 'phpunit.xml.dist' '^phpunit\.xml\.dist$'
done

if [ "$failed" -ne 0 ]; then
    exit 1
fi

# pathリポジトリが指すcore・testingは、installで作るvendor/等が混ざらないよう、別に取り出したものにする
for name in core testing; do
    git_quiet clone --quiet --branch main "$work/${name}.git" "$work/path-repositories/${name}"
done

for name in "${libraries[@]}"; do
    echo "::group::composer install && composer test (packages/${name})"
    for dependency in core testing; do
        if [ "$dependency" != "$name" ]; then
            composer --working-dir="$work/${name}" config "repositories.tmswp-${dependency}" \
                "{\"type\": \"path\", \"url\": \"$work/path-repositories/${dependency}\", \"options\": {\"symlink\": false, \"versions\": {\"tmswp/${dependency}\": \"${version}\"}}}"
        fi
    done
    composer --working-dir="$work/${name}" install --no-interaction --no-progress
    composer --working-dir="$work/${name}" test
    echo "::endgroup::"
done
