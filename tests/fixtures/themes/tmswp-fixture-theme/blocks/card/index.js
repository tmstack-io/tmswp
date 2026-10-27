// 統合テスト用: ブロックのソース。探索はビルドの出力(build/blocks/)だけを走査し、ここは見ない
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';

registerBlockType(metadata.name, {
    edit: () => <div>Fixture Card</div>,
});
