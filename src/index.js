document.addEventListener("DOMContentLoaded", function () {
  if (
    window.wc?.wcBlocksRegistry?.registerPaymentMethod &&
    window.wp?.element &&
    window.wc?.wcSettings
  ) {
    const settings = window.wc.wcSettings["paymenthood_data"] || {};
    const { createElement } = window.wp.element;

    window.wc.wcBlocksRegistry.registerPaymentMethod({
      name: "paymenthood",
      label: createElement("span", null, settings.title || "PaymentHood"),
      ariaLabel: settings.ariaLabel || "PaymentHood",
      supports: {
        features: ["products", "subscriptions", "default", "virtual"],
      },
      canMakePayment: () => Promise.resolve(true),
      content: createElement(
        "p",
        null,
        settings.description || "Pay with PaymentHood"
      ),
      edit: createElement("p", null, settings.description || "Pay with PaymentHood"),
      save: null,
    });

    console.log("[PaymentHood] registered in block checkout");
  }
});