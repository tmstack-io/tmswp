# リリース手順

TMSWP は全パッケージ lockstep バージョニングでリリースする。

1. `main` で `composer qa` と `composer monorepo:validate` を通す。
2. `CHANGELOG.md` を更新する。
3. `vendor/bin/monorepo-builder release vX.Y.Z` を実行する。
4. `git push origin main --tags` を実行する。
5. `.github/workflows/split.yml` が packages 5 件と starters 2 件を read-only リポジトリへ split する。
6. Packagist が各 split リポジトリのタグを認識していることを確認する。

`SPLIT_ACCESS_TOKEN` は split 先への push に使うトークンで、fine-grained PAT。対象は送り先の 7 リポジトリ(`tmstack-io/tmswp-*`)だけ。権限は Contents: Read and write と Workflows: Read and write だけ。monorepo の GitHub Secrets に登録する。
