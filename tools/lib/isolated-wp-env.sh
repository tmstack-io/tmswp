# shellcheck shell=bash
#
# 隔離したwp-env(リポジトリの外の、実行ごとに一意の作業ディレクトリに置いた設定ファイル・WP_ENV_HOMEと、別のポート)を
# 使うtools/のスクリプトが、sourceして使う関数。使う側: tools/acf-pro-integration.sh、tools/sample-site-acf-pro.sh。
#
# 使い方(<名前>は`tmswp-`で始まる、英小文字・数字・`-`だけの名前):
#
#   work="$(isolated_wp_env_create_work <名前>)"
#   config="${work}/<名前>/wp-env.json"
#   export WP_ENV_HOME="${work}/wp-env-home"
#   project="$(isolated_wp_env_project "$config")"
#   isolated_wp_env_remove_on_exit "$wpenv_bin" "$work" "$config" "$project"
#   ...(設定ファイルを書く等)
#   isolated_wp_env_claim "$project"
#
# isolated_wp_env_claimはwp-envのstartの前に呼び、ここから後はDockerの資源も終了時の片付けの対象になる。
#
# workは${TMPDIR}/<名前>.XXXXXXXX(正規化した絶対パス)で、configはwp-envの設定ファイル(wp-env --config)。
# 作業ディレクトリは実行ごとに一意なので、設定ファイルのパスと、それから決まるwp-envのプロジェクト名も実行ごとに一意に
# なる。片付けは、その実行が作った作業ディレクトリと、そのプロジェクトの資源だけを、完全一致で選んで消す。
# - コンテナ・ボリューム・ネットワーク: Docker composeのラベル`com.docker.compose.project=<プロジェクト名>`
# - イメージ: wp-envがビルドする完全な名前(`<プロジェクト名>-wordpress:latest`等)。イメージは同じIDを複数の環境で
#   共有することがあり、ラベルは最初にビルドした環境のものになるため、ラベルでは選ばない。名前で消すと、その名前の
#   タグだけが外れる。mariadb等の共有のイメージは消さない。
# 前回の実行の残りを、開始時に消すことはしない(同じ道具の並行の実行の環境を消さないため)。

# wp-envがビルドするイメージのサービス名(@wordpress/env 12のbuild-docker-compose-config.js)
_ISOLATED_WP_ENV_IMAGE_SERVICES=(wordpress cli tests-wordpress tests-cli)

# 実行ごとに一意の作業ディレクトリ(${TMPDIR}/<名前>.XXXXXXXX)と、設定ファイルを置くその下の<名前>/を作り、
# 作業ディレクトリの絶対パスを出す。パスは、シンボリックリンクと`..`等を解いたもので、wp-envのpath.resolveで
# 変わらない。設定ファイルを<名前>/の下に置くのは、wp-envのプロジェクト名(=Docker composeのプロジェクト名)が
# 設定ファイルのディレクトリ名から作られ、英小文字・数字・`-`だけにしておくと、composeが名前を書き換えないため。
#
#   isolated_wp_env_create_work <名前>
isolated_wp_env_create_work() {
    local name="$1"
    local work

    if [[ ! "$name" =~ ^tmswp-[a-z0-9-]+$ ]]; then
        echo "isolated-wp-env: invalid work name: ${name}" >&2
        return 1
    fi

    work="$(mktemp -d "${TMPDIR:-/tmp}/${name}.XXXXXXXX")" || return 1
    work="$(cd "$work" && pwd -P)" || return 1
    mkdir "${work}/${name}" || return 1

    echo "$work"
}

# 設定ファイルの絶対パスから、wp-envが決めるプロジェクト名(WP_ENV_HOMEの下のディレクトリの名前で、Docker composeの
# プロジェクト名)を出す。決め方は@wordpress/env 12のlib/config/load-config.js(path.resolveしたパスから、
# wp-env-<ディレクトリ名>-<ファイル名>-<パスのmd5の先頭8文字>)。パスがpath.resolveで変わる(相対・`..`・二重の`/`を
# 含む)ときは、名前がずれるため失敗にする。使う側は、起動の後にisolated_wp_env_assert_startedで、決め方が
# 変わっていないことを確かめる。
#
#   isolated_wp_env_project <設定ファイルの絶対パス>
isolated_wp_env_project() {
    local project

    # shellcheck disable=SC2016 # ${...}はJavaScriptのテンプレート文字列で、シェルに展開させない
    project="$(node -e '
const crypto = require("crypto");
const path = require("path");
const file = process.argv[1];
if (path.resolve(file) !== file) {
    console.error(`isolated-wp-env: the config path is not normalized: ${file}`);
    process.exit(1);
}
const hash = crypto.createHash("md5").update(file).digest("hex").slice(0, 8);
console.log(["wp-env", path.basename(path.dirname(file)), path.basename(file, ".json"), hash].join("-"));
' "$1")" || return 1

    if [[ ! "$project" =~ ^wp-env-tmswp-[a-z0-9-]+-wp-env-[0-9a-f]{8}$ ]]; then
        echo "isolated-wp-env: unexpected wp-env project name: ${project}" >&2
        return 1
    fi

    echo "$project"
}

# 終了時の片付けがDockerの資源も消すのは、isolated_wp_env_claimで引き受けたプロジェクトだけ(空なら作業ディレクトリだけ)
_ISOLATED_WP_ENV_CLAIMED=""

# プロジェクトのDockerの資源を、この実行のものとして引き受ける。資源が1つも無いことを確かめ、無ければ、終了時の片付け
# (isolated_wp_env_remove_on_exit)がDockerの資源も消すようにする。既に資源があるとき(ほかの実行のもの)や、Dockerの
# 列挙に失敗したときは、引き受けずに1を返す。このときの終了時の片付けは、作業ディレクトリだけを消し、Dockerの資源に
# 触れない。wp-envのstartより前に呼ぶ。フラグを親のシェルに残すため、コマンド置換等のサブシェルの中で呼ばない。
#
#   isolated_wp_env_claim <プロジェクト名>
isolated_wp_env_claim() {
    local resources

    resources="$(_isolated_wp_env_resources "$1")" || return 1

    if [ -n "$resources" ]; then
        echo "isolated-wp-env: Docker resources of ${1} already exist; leaving them untouched:" >&2
        echo "$resources" >&2
        return 1
    fi

    _ISOLATED_WP_ENV_CLAIMED="$1"
}

# 起動の後に、wp-envがWP_ENV_HOMEの下にプロジェクト名のディレクトリを作ったことを確かめる。
#
#   isolated_wp_env_assert_started <プロジェクト名>
isolated_wp_env_assert_started() {
    if [ ! -f "${WP_ENV_HOME:?}/${1}/docker-compose.yml" ]; then
        echo "isolated-wp-env: wp-env did not create ${WP_ENV_HOME}/${1}; the project name rule may have changed" >&2
        return 1
    fi
}

# 作業ディレクトリを消す。Dockerの資源には触れない。消す前に_isolated_wp_env_check_workで確かめ、満たさなければ
# 何も消さずに1を返す。
#
#   isolated_wp_env_remove_work <作業ディレクトリ> <設定ファイル> <プロジェクト名>
isolated_wp_env_remove_work() {
    _isolated_wp_env_check_work "$@" || return 1

    if ! rm -rf -- "$1" || [ -e "$1" ]; then
        echo "isolated-wp-env: ${1} remains" >&2
        return 1
    fi
}

# 環境を消す。消す前に_isolated_wp_env_check_workで確かめ、満たさなければ、Dockerにも作業ディレクトリにも触れずに
# 1を返す。まずwp-envのcleanup(コンテナ・ボリューム・ネットワークとWP_ENV_HOMEの下のファイル。イメージは残す)を
# 行い、続けて、プロジェクトのコンテナ・ボリューム・ネットワーク・イメージ(選び方はこのファイルの先頭)を消し、
# 作業ディレクトリを消す。後者は、起動の途中の中断でwp-envのcleanupが「Environment not initialized」で断る場合の
# 残りを消すためである。どれかが失敗しても残りの片付けを続け、最後に資源が残っていないことを確かめる。消すコマンドや
# Dockerの列挙が失敗したとき、または資源が残っているときは、終了コード1で返す(握りつぶさない)。
#
#   isolated_wp_env_remove <wp-envのbinのパス> <作業ディレクトリ> <設定ファイル> <プロジェクト名>
isolated_wp_env_remove() {
    local wpenv_bin="$1"
    local work="$2"
    local config="$3"
    local project="$4"
    local failed=0
    local list
    local item
    local remaining

    _isolated_wp_env_check_work "$work" "$config" "$project" || return 1

    if [ -f "$config" ] && [ -d "${WP_ENV_HOME:?}/${project}" ]; then
        (cd "${config%/*}" && node "$wpenv_bin" "--config=${config}" cleanup --force) \
            || echo "isolated-wp-env: wp-env cleanup failed; removing the Docker resources of ${project} directly" >&2
    fi

    if list="$(_isolated_wp_env_list ps -a --filter "label=com.docker.compose.project=${project}" --format '{{.ID}}')"; then
        for item in $list; do
            docker rm --force --volumes "$item" > /dev/null || failed=1
        done
    else
        failed=1
    fi

    if list="$(_isolated_wp_env_list volume ls --filter "label=com.docker.compose.project=${project}" --format '{{.Name}}')"; then
        for item in $list; do
            docker volume rm "$item" > /dev/null || failed=1
        done
    else
        failed=1
    fi

    if list="$(_isolated_wp_env_list network ls --filter "label=com.docker.compose.project=${project}" --format '{{.ID}}')"; then
        for item in $list; do
            docker network rm "$item" > /dev/null || failed=1
        done
    else
        failed=1
    fi

    if list="$(_isolated_wp_env_images "$project")"; then
        for item in $list; do
            docker rmi "$item" > /dev/null || failed=1
        done
    else
        failed=1
    fi

    rm -rf -- "$work" || failed=1

    if remaining="$(_isolated_wp_env_resources "$project")"; then
        if [ -n "$remaining" ]; then
            echo "isolated-wp-env: Docker resources of ${project} remain:" >&2
            echo "$remaining" >&2
            failed=1
        fi
    else
        failed=1
    fi

    if [ -e "$work" ]; then
        echo "isolated-wp-env: ${work} remains" >&2
        failed=1
    fi

    return "$failed"
}

# 終了時(成功・失敗・SIGINT・SIGTERM)の片付けのtrapを掛ける。作業ディレクトリをisolated_wp_env_create_workで
# 作った直後に掛ける。片付けは2段で、isolated_wp_env_claimでプロジェクトを引き受けた後はisolated_wp_env_remove
# (Dockerの資源と作業ディレクトリ)、引き受ける前はisolated_wp_env_remove_work(作業ディレクトリだけ)を行う。
# 片付けに失敗したら、元の終了コードが0でも1にする。
#
#   isolated_wp_env_remove_on_exit <wp-envのbinのパス> <作業ディレクトリ> <設定ファイル> <プロジェクト名>
isolated_wp_env_remove_on_exit() {
    local args

    args="$(printf ' %q' "$@")"
    # shellcheck disable=SC2064 # 引数は、trapを掛けた時点の値で埋め込む
    trap "_isolated_wp_env_on_exit${args}" EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
}

_isolated_wp_env_on_exit() {
    local status=$?
    local removed=0

    trap - EXIT

    if [ -n "$_ISOLATED_WP_ENV_CLAIMED" ] && [ "$_ISOLATED_WP_ENV_CLAIMED" = "$4" ]; then
        isolated_wp_env_remove "$@" || removed=1
    else
        isolated_wp_env_remove_work "$2" "$3" "$4" || removed=1
    fi

    if [ "$removed" -ne 0 ]; then
        echo "isolated-wp-env: failed to clean up ${4} (${2}); see the comment at the top of the script to remove it by hand" >&2
        [ "$status" -ne 0 ] || status=1
    fi

    exit "$status"
}

# 消す前の確かめ。作業ディレクトリが、isolated_wp_env_create_workが作る形(名前が<名前>.XXXXXXXX)の、シンボリック
# リンクでない正規化した絶対パスであること、設定ファイルのパスが<作業ディレクトリ>/<名前>/wp-env.jsonと文字列で一致
# すること(`..`等の非正規の表記や、ほかの場所を通らない)、<名前>/と設定ファイルがシンボリックリンクでないこと
# (設定ファイルはまだ無くてもよいが、あればふつうのファイル)、プロジェクト名が設定ファイルのパスから決まる名前と一致
# することを確かめる。満たさなければ理由を出して1を返す。
#
#   _isolated_wp_env_check_work <作業ディレクトリ> <設定ファイル> <プロジェクト名>
_isolated_wp_env_check_work() {
    local work="$1"
    local config="$2"
    local project="$3"
    local base="${work##*/}"
    local dir="${work}/${base%.*}"
    local reason=""

    if [[ ! "$base" =~ ^tmswp-[a-z0-9-]+\.[A-Za-z0-9]{8}$ ]]; then
        reason='the work directory name is not <name>.XXXXXXXX'
    elif [ "$config" != "${dir}/wp-env.json" ]; then
        reason="the config is not ${dir}/wp-env.json"
    elif [ ! -d "$work" ] || [ -L "$work" ] || [ "$(cd "$work" && pwd -P)" != "$work" ]; then
        reason='the work directory is not a normalized real directory'
    elif [ -L "$dir" ] || { [ -e "$dir" ] && [ ! -d "$dir" ]; }; then
        reason="${dir} is not a real directory"
    elif [ -L "$config" ] || { [ -e "$config" ] && [ ! -f "$config" ]; }; then
        reason='the config is not a regular file'
    elif [ "$(isolated_wp_env_project "$config")" != "$project" ]; then
        reason="the project is not the one derived from the config: ${project}"
    fi

    if [ -n "$reason" ]; then
        echo "isolated-wp-env: refusing to remove ${work} (config: ${config}, project: ${project}): ${reason}" >&2
        return 1
    fi
}

# `docker <引数>`の出力を出す。Dockerが失敗したら、理由を出して1を返す(列挙の失敗を「資源無し」にしない)。
_isolated_wp_env_list() {
    local output

    if ! output="$(docker "$@")"; then
        echo "isolated-wp-env: docker $* failed" >&2
        return 1
    fi

    if [ -n "$output" ]; then
        echo "$output"
    fi
}

# プロジェクトのイメージ(wp-envがビルドする完全な名前のうち、あるもの)。
_isolated_wp_env_images() {
    local service
    local output

    for service in "${_ISOLATED_WP_ENV_IMAGE_SERVICES[@]}"; do
        output="$(_isolated_wp_env_list images --filter "reference=${1}-${service}:latest" --format '{{.Repository}}:{{.Tag}}')" || return 1

        if [ -n "$output" ]; then
            echo "$output"
        fi
    done
}

# プロジェクトの資源(コンテナ・ボリューム・ネットワーク・イメージ)の一覧(1行に「種類 名前」)。Dockerの列挙に
# 失敗したら1を返す。
_isolated_wp_env_resources() {
    local label="label=com.docker.compose.project=${1}"
    local images
    local image

    _isolated_wp_env_list ps -a --filter "$label" --format 'container {{.Names}}' || return 1
    _isolated_wp_env_list volume ls --filter "$label" --format 'volume {{.Name}}' || return 1
    _isolated_wp_env_list network ls --filter "$label" --format 'network {{.Name}}' || return 1
    images="$(_isolated_wp_env_images "$1")" || return 1

    for image in $images; do
        echo "image ${image}"
    done
}
