# リリース手順

TMSWPは全パッケージlockstepバージョニングでリリースする(docs/01 §4.1)。リリースの操作は`vendor/bin/monorepo-builder release`で行い、このコマンドがmainとタグを自らpushする(手順4)。mainへのpushとタグ付けは、毎回ユーザーに確認してから行う。

## 手順

1. **mainで検査を通す。** `composer qa`と`composer monorepo:validate`を通す。各パッケージをsplit相当で取り出して確かめる(下の「split相当の確認」)。
2. **版の準備をmainに入れる。**次の2つを、リリースの前のコミットでmainに入れる。
   - `CHANGELOG.md`の`## Unreleased`の節に、今回の版の変更を書く。見出しの文字列は変えない(手順4で`AddTagToChangelogReleaseWorker`が`## Unreleased`を探して書き換える)。
   - `packages/core/src/Kernel/Kernel.php`の`Kernel::VERSION`を、リリースの版(`vX.Y.Z`の`X.Y.Z`)にする。食い違うと、そのタグのtmswp/coreを入れたサイトはboot時の版の照合で起動しない(docs/02 §3.9)。
3. **作業ツリーを確かめる。** mainをcheckoutし、origin/mainと同じで、作業ツリーに変更が無いこと(`git status --short`が空)を確かめる。手順4の`TagVersionReleaseWorker`は`git add .`で作業ツリーのすべての変更をリリースのコミットに含めるためである。手元にだけあるタグが無いこと(`git push --tags --dry-run`が新しいタグを送らないこと)も確かめる。`PushTagReleaseWorker`は`git push --tags`で手元のすべてのタグを送るためである。
4. **`monorepo-builder release`を実行する。**まず`vendor/bin/monorepo-builder release vX.Y.Z --dry-run`で実行する内容を表示し、ユーザーの確認を取ってから`vendor/bin/monorepo-builder release vX.Y.Z`を実行する。monorepo-builder.phpのワーカーが、次の順に実行する。手で`git push`する手順は無い。
   1. packages/*・starters/*のcomposer.jsonのtmswp/*の制約(`require`と`require-dev`)を`^X.Y`にする。PATCHが0より大きい版では`^X.Y.Z`にする(`SetCurrentMutualDependenciesReleaseWorker`)。
   2. `CHANGELOG.md`の`## Unreleased`を`## X.Y.Z - <日付>`にする(`AddTagToChangelogReleaseWorker`)。
   3. `git add .`・`git commit -m "prepare release"`・**`git push origin main`**を行い、`git tag vX.Y.Z`で軽量タグを作る(`TagVersionReleaseWorker`)。
   4. **`git push --tags`**でタグを送る(`PushTagReleaseWorker`)。
   5. tmswp/*の制約を`^X.(Y+1)`にする(`SetNextMutualDependenciesReleaseWorker`)。branch-aliasを`X.(Y+1).x-dev`にする(`UpdateBranchAliasReleaseWorker`)。branch-aliasを更新するのは、`extra.branch-alias`を既に持つcomposer.json(packages/*の6つ)だけで、スターターには足さない。
   6. `git add .`・`git commit --allow-empty -m "open X.(Y+1).x-dev"`・**`git push origin main`**を行う(`PushNextDevReleaseWorker`)。

   コミットのメッセージ(`prepare release`・`open X.(Y+1).x-dev`)はmonorepo-builderが決めるもので、変えられない。
5. **splitを確かめる。** `.github/workflows/split.yml`が、mainへのpushで各splitリポジトリのmainを、タグのpushで同名のタグを、8つのread-onlyリポジトリ(packagesのcore・thumbnail・settings-page・acf-bridge・ja-toolkit・testingと、startersのtheme・plugin)へ複製する。GitHub Actionsで8つのジョブが成功したことを確かめる。
6. **Packagistを確かめる。** Packagistが各splitリポジトリのタグを認識していることを確かめる。

## split相当の確認

各パッケージが、splitで取り出した単独の状態で`composer install && composer test`を通すことを、リリースの前に確かめる。リポジトリの外の一時ディレクトリで行い、終わったら消す。

1. 作業ツリーの内容でコミットしたリポジトリ(mainのcloneなど)で、パッケージごとに`git subtree split --prefix=packages/<pkg> -b split-<pkg>`を実行し、そのブランチを一時ディレクトリにcloneして、ブランチの名前を`main`にする(composerがこのパッケージの版を`dev-main`、branch-aliasで`X.Y.x-dev`とみなす)。
2. tmswp/coreとtmswp/testingは、リリースの前はPackagistに無い。tmswp/core・tmswp/testingを要るパッケージでは、cloneのcomposer.jsonに、同じくsplitで取り出したcore・testingを指すpathリポジトリ(`"options": {"symlink": false, "versions": {"tmswp/core": "X.Y.Z"}}`。版は`Kernel::VERSION`)を足す。リポジトリのpackages/*/composer.jsonにはpathリポジトリを足さない。
3. cloneで`composer install && composer test`を実行する。`composer test`は、そのパッケージの`phpunit.xml.dist`で`tests/Unit/`を走らせる。
4. スターター(`starters/theme`・`starters/plugin`)は、同じく取り出してcoreのpathリポジトリを足し、`composer validate`と`composer install`を確かめる。

配布物から開発用のファイルが外れていることは、`git archive HEAD packages/<pkg> | tar -t`に`tests/`・`phpunit.xml.dist`・`.gitattributes`・`.DS_Store`が無いことで確かめる(各パッケージの`.gitattributes`の`export-ignore`)。

## SPLIT_ACCESS_TOKEN

`SPLIT_ACCESS_TOKEN`はsplit先へのpushに使うトークンで、fine-grained PAT。対象は送り先の8リポジトリ(`tmstack-io/tmswp-*`)だけ。権限はContents: Read and writeとWorkflows: Read and writeだけ。monorepoのGitHub Secretsに登録する。
