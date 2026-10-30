# リリース手順

TMSWPは全パッケージlockstepバージョニングでリリースする(docs/01 §4.1)。mainへの反映は、PR(`rewrite/v0`→`main`)のマージで行う。リリースの操作`vendor/bin/monorepo-builder release vX.Y.Z`は、リリースの前の検査に通れば注釈付きのタグを作り、そのタグだけをpushする(mainへのcommit・pushと版の書き換えはしない)。PRのマージとタグ付けは、毎回ユーザーに確認してから行う。

## 手順

1. **開発のブランチで検査を通す。** PRの元のブランチ(`rewrite/v0`)で`composer qa`と`composer monorepo:validate`を通し、CI(`.github/workflows/qa.yml`)の全ジョブが成功していることを確かめる。各パッケージのsplit相当の確認は、CIのSplit Packages Checkのジョブが行う(手元で行う場合は下の「split相当の確認」)。
2. **版がそろっていることを確かめる。** `packages/core/src/Kernel/Kernel.php`の`Kernel::VERSION`、packages/*・starters/*のcomposer.jsonのtmswp/*の制約(`require`と`require-dev`)とbranch-alias、examples/sample-siteのcomposer.jsonのtmswp/*の制約とpathリポジトリの`versions`は、版の最初のタスクで1つのコミットで上げてある(制約は`^X.Y`、PATCHが0より大きい版では`^X.Y.Z`。branch-aliasは`X.Y.x-dev`)。packages/*のrules/とREADME.mdの設計文書へのリンク(`https://github.com/tmstack-io/tmswp/blob/vX.Y.Z/docs/...`)の版も、同じコミットで上げてある(ユニットテスト`tests/Unit/RepositoryScanTest.php`が`Kernel::VERSION`との一致を確かめる)。リリースの道具は版を書き換えず、リリースの後に次の版へも上げない。食い違いは手順5の検査が止める。`Kernel::VERSION`が食い違ったまま公開すると、そのタグのtmswp/coreを入れたサイトはboot時の版の照合で起動しない(docs/02 §3.9)。
3. **リリースの準備のコミットを置く。** `CHANGELOG.md`の`## Unreleased`の見出しを、手で`## vX.Y.Z — 題名`(過去の版の見出しと同じ形。区切りは全角のダッシュ`—`)に改めるコミットを、開発のブランチに置く。次の版のUnreleasedの節は、次の版の最初のタスクで開く。
4. **mainへ反映する。** ユーザーの確認を経て、PR(`rewrite/v0`→`main`)をマージする。マージの方法(マージコミット・squash等)もユーザーに確かめる。mainへのpushで、`.github/workflows/split.yml`が8つの同期先のmainを更新する。同期先のリポジトリを新しく作った場合は、マージの前に、下の「新しい同期先のリポジトリ」の初期コミットを置く。
5. **タグを作る。** mainをcheckoutしてorigin/mainにそろえ(`git checkout main && git pull --ff-only origin main`)、`vendor/bin/monorepo-builder release vX.Y.Z --dry-run`で実行するワーカーを表示し、ユーザーの確認を取ってから`vendor/bin/monorepo-builder release vX.Y.Z`を実行する。monorepo-builder.phpに登録した`tools/release/`の2つのワーカーが、次の順に実行する。手で`git tag`・`git push`する手順は無い。
   1. **リリースの前の検査**(`CheckReleaseReadinessReleaseWorker`)。次のどれか1つでも満たさなければ、満たさない項目をすべて並べて止まり、タグを作らない。
      - 今のブランチが`main`である。
      - originにmainのブランチがあり、`git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main`で取り直した`origin/main`と、HEADが同じコミットである(設定のfetchのrefspecと、手元に残った古い追跡refには頼らない)。
      - 作業ツリーが空である(`git status --porcelain --untracked-files=all`が空)。
      - タグ`vX.Y.Z`が手元にも`origin`にも無い。
      - 手順2の版が、すべてタグの版と一致する。
      - `CHANGELOG.md`に`## vX.Y.Z — 題名`の見出しがある。
   2. **注釈付きのタグ**(`CreateAnnotatedTagReleaseWorker`)。`git tag -a vX.Y.Z -m vX.Y.Z`でHEADに注釈付きのタグを作り、`git push origin refs/tags/vX.Y.Z`でそのタグだけを送る(手元のほかのタグは送らない)。pushが失敗すると、手元のタグを残したまま止まる。原因を直してから`git push origin refs/tags/vX.Y.Z`で送るか、`git tag -d vX.Y.Z`で消してから手順5をやり直す。

   `--dry-run`は、ワーカーの説明を表示するだけで、検査も実行しない(monorepo-builderの`release`は、`--dry-run`のときワーカーの処理を呼ばない)。検査は`--dry-run`を付けない実行の最初に行われ、通らなければタグを作らずに止まる。
6. **splitを確かめる。** split.ymlが、タグのpushで同名のタグを8つのread-onlyリポジトリ(packagesのcore・thumbnail・settings-page・acf-bridge・ja-toolkit・testingと、startersのtheme・plugin)へ複製する。GitHub Actionsで8つのジョブが成功したことを確かめる。
7. **Packagistを確かめる。** Packagistが各splitリポジトリのタグを認識していることを確かめる。

## split相当の確認

各パッケージを、split.ymlの同期と同じ形で取り出し、配布物の中身と、ライブラリの単独の`composer install && composer test`を確かめる。CIのSplit Packages Checkのジョブが、すべてのプルリクエストとmainへのpushで実行する。手元では、確かめたいコミットをcheckoutして次を実行する(作業ツリーのコミットしていない変更は対象にならない)。

```bash
tools/ci/split-check.sh
```

このスクリプトは、一時ディレクトリで次を行い、終わったら一時ディレクトリを消す。どれかが失敗すれば非0で終わる。

1. パッケージごとに、HEADのそのディレクトリ(`packages/<pkg>`・`starters/<name>`)の中身だけを持つコミットを作り、一時のリポジトリのmainにする(composerがこのパッケージの版を`dev-main`、branch-aliasで`X.Y.x-dev`とみなす)。split.ymlと同じく、正本の`.github/split/close-pull-request.yml`を`.github/workflows/close-pull-request.yml`に置いてコミットする。
2. packages/*の6つは、`git archive HEAD`に`tests/`・`phpunit.xml.dist`・`.gitattributes`・`.DS_Store`・`.github/`が入らないこと(各パッケージの`.gitattributes`の`export-ignore`)を確かめる。starters/*の2つは、`.github/`・`.DS_Store`・`node_modules/`が入らず、作ったプロジェクトが使う`tests/`と`phpunit.xml.dist`が入ることを確かめる。
3. packages/*の6つは、tmswp/core・tmswp/testingを、同じく取り出したcore・testingを指すpathリポジトリ(`"options": {"symlink": false, "versions": {"tmswp/core": "X.Y.Z"}}`。版は`Kernel::VERSION`)で入れ、`composer install && composer test`を実行する。`composer test`は、そのパッケージの`phpunit.xml.dist`で`tests/Unit/`を走らせる。tmswp/coreとtmswp/testingは、リリースの前はPackagistに無いためである。リポジトリのpackages/*/composer.jsonにはpathリポジトリを足さない。スターターはinstall・testを行わない(スターターから作ったプロジェクトはCIのStarter Projectsのジョブが確かめる)。

## 新しい同期先のリポジトリ

同期先のリポジトリを新しく作ったときは、最初の同期(split.ymlの実行)の前に、既定のブランチ`main`に初期コミットを1つ置く。split.ymlが固定しているsplitのアクション(`danharrin/monorepo-split-github-action`のv2.4.5、コミット`14e42e24`)は、送り先にそのブランチが無いと、`git checkout -b main`の直後に`git push origin main`を実行する。空のリポジトリにはコミットが無いため、このpushが`error: src refspec main does not match any`で失敗する(v0.9.0の最初の同期では、8つのジョブがすべてこれで失敗した。docs/acceptance/v0.9.0.mdの4.2)。

初期コミットは、GitHubのAPIで中身が改行1つの`.gitkeep`を作って置く(`<名前>`は`core`・`starter-theme`等で、リポジトリは`tmstack-io/tmswp-<名前>`)。`content`はファイルの中身のBase64で、`Cg==`は改行1つである。

```bash
gh api -X PUT repos/tmstack-io/tmswp-<名前>/contents/.gitkeep -f message=同期を始めるための初期コミット -f content=Cg==
```

この`.gitkeep`は、最初の同期のコミットで消える。アクションは、送り先をcloneした`.git`だけを新しい作業ディレクトリに写してから、パッケージのディレクトリの中身を写して`git add .`でコミットするためである。初期コミットを置いてから、split.ymlを実行する(失敗した実行の再実行でもよい)。あわせて、新しいリポジトリを`SPLIT_ACCESS_TOKEN`のトークンの対象に足す(下の節)。

## SPLIT_ACCESS_TOKEN

`SPLIT_ACCESS_TOKEN`はsplit先へのpushに使うトークンで、fine-grained PAT。対象は送り先の8リポジトリ(`tmstack-io/tmswp-*`)だけ。権限はContents: Read and writeとWorkflows: Read and writeだけ(同期先の`.github/workflows/`を更新するため)。monorepoのGitHub Secretsに登録する。未登録(空)のとき、split.ymlのジョブは同期せずに失敗する。mainへのpushでも失敗するため、PRのマージの前に登録する。

## 同期先のプルリクエスト

同期先(read-onlyリポジトリ)に送られたプルリクエストは、`.github/workflows/close-pull-request.yml`が自動で閉じ、英語の文面でmonorepo(https://github.com/tmstack-io/tmswp)へ案内する。正本はmonorepoの`.github/split/close-pull-request.yml`で、split.ymlが同期の前に各パッケージの`.github/workflows/`へ写す。文面を変えるときは正本を直し、PRのマージでmainへ反映する(次の同期で8つの同期先へ入る)。
