import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';

// 表示はrender.php(サーバー)が作るため、エディタでは目印だけを出し、保存する内容は持たない
registerBlockType(metadata.name, {
    edit: () => <p {...useBlockProps()}>{metadata.title}</p>,
    save: () => null,
});
