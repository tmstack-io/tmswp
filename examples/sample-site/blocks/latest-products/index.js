import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

// 表示はrender.php(サーバー)が作る。エディタでは同じ表示をServerSideRenderで見せ、件数だけを設定させる。
// 保存する内容は持たない(属性のcountだけがブロックのコメントに保存される)
registerBlockType(metadata.name, {
    edit: ({ attributes, setAttributes }) => (
        <div {...useBlockProps()}>
            <InspectorControls>
                <PanelBody title={__('Settings', 'sample-site')}>
                    <RangeControl
                        label={__('Number of products', 'sample-site')}
                        value={attributes.count}
                        onChange={(count) => setAttributes({ count })}
                        min={1}
                        max={12}
                    />
                </PanelBody>
            </InspectorControls>
            <ServerSideRender block={metadata.name} attributes={attributes} />
        </div>
    ),
    save: () => null,
});
