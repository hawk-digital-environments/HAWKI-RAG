document$.subscribe(function () {
  const scheme = document.body.getAttribute("data-md-color-scheme");
  const theme = scheme === "slate" ? "dark" : "default";

  mermaid.initialize({
    startOnLoad: false,
    theme: theme,
    securityLevel: "loose",
    flowchart: {
      htmlLabels: true,
      curve: "basis"
    }
  });

  document.querySelectorAll(".mermaid").forEach((node) => {
    node.removeAttribute("data-processed");
  });

  mermaid.run({
    querySelector: ".mermaid"
  });
});
