window.addEventListener("load", function () {
    window.ui = SwaggerUIBundle({
        url: document.getElementById("swagger-ui").dataset.specUrl,
        dom_id: "#swagger-ui",
        deepLinking: true,
        displayRequestDuration: true,
        filter: true,
        validatorUrl: null,
        persistAuthorization: false,
        supportedSubmitMethods: ["get"],
        presets: [SwaggerUIBundle.presets.apis],
        layout: "BaseLayout"
    });
});
