// 統合テスト用: blocks/card/index.jsのビルドの出力を、Nodeのビルドを使わずに手で置いたもの
(function (blocks, element) {
    blocks.registerBlockType('tmswp-fixture/card', {
        edit: function () {
            return element.createElement('div', null, 'Fixture Card');
        },
    });
})(window.wp.blocks, window.wp.element);
