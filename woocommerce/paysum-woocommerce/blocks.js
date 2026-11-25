(() => {
    const { title } = window.wc.wcSettings.getSetting('paysum_data', {});
    const label = title || 'Pay with PaySum';
    const content = window.wp.element.createElement('span', null, 'Continue to PaySum to pay for your order.');
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'paysum',
        label,
        ariaLabel: label,
        canMakePayment: () => true,
        content,
        edit: content,
        supports: { features: ['products'] },
    });
})();
