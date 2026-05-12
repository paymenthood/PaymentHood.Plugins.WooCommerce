document.addEventListener("DOMContentLoaded", function () {
  if (
    window.wc?.wcBlocksRegistry?.registerPaymentMethod &&
    window.wp?.element &&
    window.wc?.wcSettings
  ) {
    const settings = window.wc.wcSettings["paymenthood_data"] || {};
    const { createElement } = window.wp.element;

    const supportedMethods = Array.isArray(settings.supportedMethods)
      ? settings.supportedMethods
      : [];

    const renderMethod = (method, index) => {
      if (method.type === "credit_card") {
        return createElement(
          "div",
          { className: "paymenthood-provider-chip paymenthood-provider-chip--card", key: `card-${index}` },
          createElement("span", { className: "paymenthood-provider-chip__card-icon", "aria-hidden": true }),
          createElement("span", { className: "paymenthood-provider-chip__label" }, method.label || "Credit card")
        );
      }

      const pictureChildren = [];

      if (method.icon_dark) {
        pictureChildren.push(
          createElement("source", {
            media: "(prefers-color-scheme: dark)",
            srcSet: method.icon_dark,
            key: `source-${index}`,
          })
        );
      }

      if (method.icon_light || method.icon_dark) {
        pictureChildren.push(
          createElement("img", {
            className: "paymenthood-provider-chip__logo",
            src: method.icon_light || method.icon_dark,
            alt: method.label || "Payment provider",
            key: `img-${index}`,
          })
        );

        return createElement(
          "div",
          { className: "paymenthood-provider-chip", key: `provider-${index}`, title: method.label || "Payment provider" },
          createElement("picture", null, ...pictureChildren)
        );
      }

      return createElement(
        "div",
        { className: "paymenthood-provider-chip", key: `provider-${index}` },
        createElement("span", { className: "paymenthood-provider-chip__label" }, method.label || "Payment provider")
      );
    };

    const content = createElement(
      "div",
      { className: "paymenthood-checkout-card paymenthood-checkout-card--blocks" },
      createElement("div", { className: "paymenthood-checkout-card__topline" }, "Secure hosted checkout"),
      createElement(
        "div",
        { className: "paymenthood-checkout-card__header" },
        settings.logoUrl
          ? createElement("img", {
              className: "paymenthood-checkout-card__logo",
              src: settings.logoUrl,
              alt: "PaymentHood logo",
            })
          : null,
        createElement(
          "div",
          null,
          createElement("h3", { className: "paymenthood-checkout-card__title" }, settings.title || "PaymentHood"),
          createElement(
            "p",
            { className: "paymenthood-checkout-card__description" },
            settings.description || "Pay securely using PaymentHood."
          )
        )
      ),
      supportedMethods.length
        ? createElement(
            "div",
            { className: "paymenthood-provider-strip", "aria-label": "Supported payment providers" },
            ...supportedMethods.map(renderMethod)
          )
        : null
    );

    window.wc.wcBlocksRegistry.registerPaymentMethod({
      name: "paymenthood",
      label: createElement("span", null, settings.title || "PaymentHood"),
      ariaLabel: settings.ariaLabel || "PaymentHood",
      supports: {
        features: ["products", "subscriptions", "default", "virtual"],
      },
      canMakePayment: () => Promise.resolve(true),
      content,
      edit: content,
      save: null,
    });

    console.log("[PaymentHood] registered in block checkout");
  }
});