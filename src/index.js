(function () {
  if (
    window.wc?.wcBlocksRegistry?.registerPaymentMethod &&
    window.wp?.element &&
    window.wc?.wcSettings
  ) {
    const settings = window.wc.wcSettings["paymenthood_data"] || {};
    const { createElement, useState, useEffect, useRef } = window.wp.element;
    const { __ } = window.wp.i18n || { __: (text) => text };

    const supportedMethods = Array.isArray(settings.supportedMethods)
      ? settings.supportedMethods
      : [];

    // Function component so hooks work per-chip instance.
    const ProviderChip = function (props) {
      const method = props.method;

      if (method.type === "credit_card") {
        return createElement(
          "div",
          { className: "paymenthood-provider-chip paymenthood-provider-chip--card" },
          createElement("span", { className: "paymenthood-provider-chip__card-icon", "aria-hidden": true }),
          createElement("span", { className: "paymenthood-provider-chip__label" }, method.label || __("Credit card", "paymenthood"))
        );
      }

      const iconUrl = method.icon_light || method.icon_dark || "";
      const label = method.label || __("Payment provider", "paymenthood");

      // status: "loading" | "loaded" | "error"
      const initialStatus = iconUrl ? "loading" : "loaded";
      const statusState = useState(initialStatus);
      const status = statusState[0];
      const setStatus = statusState[1];

      const imgRef = useRef(null);

      // Catch images that were already in browser cache when the component
      // mounted — their native load event fires before React attaches its
      // synthetic listener, so onLoad would never be called.
      useEffect(function () {
        if (imgRef.current && imgRef.current.complete && status === "loading") {
          setStatus("loaded");
        }
      }, []);

      const isLoading = status === "loading";
      const isError   = status === "error";

      return createElement(
        "div",
        {
          className: isLoading
            ? "paymenthood-provider-chip paymenthood-provider-chip--loading"
            : "paymenthood-provider-chip",
          title: label,
        },
        iconUrl
          ? createElement("img", {
              ref: imgRef,
              className: "paymenthood-provider-chip__logo",
              src: iconUrl,
              alt: label,
              width: 84,
              height: 26,
              style: isError ? { display: "none" } : {},
              onLoad:  function () { setStatus("loaded"); },
              onError: function () { setStatus("error");  },
            })
          : null,
        createElement(
          "span",
          {
            className: "paymenthood-provider-chip__label",
            // While loading: invisible (skeleton covers the chip).
            // After loaded: hidden (logo takes its place).
            // After error: visible (fallback text).
            style: iconUrl
              ? (isLoading ? { opacity: 0 } : (!isError ? { display: "none" } : {}))
              : {},
          },
          label
        )
      );
    };

    const isSandbox = !!settings.isSandbox;
    const baseTitle = settings.title || "PaymentHood";

    const label = isSandbox
      ? createElement("span", { style: { display: "inline-flex", alignItems: "center", gap: "6px" } },
          baseTitle,
          createElement("span", { className: "paymenthood-sandbox-badge paymenthood-sandbox-badge--inline" }, __("Sandbox", "paymenthood"))
        )
      : createElement("span", null, baseTitle);

    const content = createElement(
      "div",
      { className: "paymenthood-checkout-card paymenthood-checkout-card--blocks" },
      supportedMethods.length
        ? createElement(
            "div",
            { className: "paymenthood-provider-strip", "aria-label": "Supported payment providers" },
            ...supportedMethods.map(function (method, index) {
              return createElement(ProviderChip, { method: method, key: "provider-" + index });
            })
          )
        : null
    );

    window.wc.wcBlocksRegistry.registerPaymentMethod({
      name: "paymenthood",
      label: label,
      ariaLabel: isSandbox ? baseTitle + " " + __("Sandbox", "paymenthood") : baseTitle,
      supports: {
        features: ["products", "subscriptions", "default", "virtual"],
      },
      canMakePayment: () => Promise.resolve(true),
      content,
      edit: content,
      save: null,
    });
  }
})();
