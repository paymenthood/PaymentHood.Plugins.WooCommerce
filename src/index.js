(function () {
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

      const iconUrl = method.icon_light || method.icon_dark || "";
      const label = method.label || "Payment provider";

      return createElement(
        "div",
        { className: "paymenthood-provider-chip", key: `provider-${index}`, title: label },
        iconUrl
          ? createElement("img", {
              className: "paymenthood-provider-chip__logo",
              src: iconUrl,
              alt: label,
              width: 84,
              height: 26,
              onError: (e) => {
                e.target.style.display = "none";
                const lb = e.target.parentNode.querySelector(".paymenthood-provider-chip__label");
                if (lb) lb.style.display = "";
              },
            })
          : null,
        createElement(
          "span",
          { className: "paymenthood-provider-chip__label", style: iconUrl ? { display: "none" } : {} },
          label
        )
      );
    };

    const isSandbox = !!settings.isSandbox;
    const baseTitle = settings.title || "PaymentHood";

    const label = isSandbox
      ? createElement("span", { style: { display: "inline-flex", alignItems: "center", gap: "6px" } },
          baseTitle,
          createElement("span", { className: "paymenthood-sandbox-badge paymenthood-sandbox-badge--inline" }, "Sandbox")
        )
      : createElement("span", null, baseTitle);

    const content = createElement(
      "div",
      { className: "paymenthood-checkout-card paymenthood-checkout-card--blocks" },
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
      label: label,
      ariaLabel: isSandbox ? baseTitle + " Sandbox" : baseTitle,
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
})();