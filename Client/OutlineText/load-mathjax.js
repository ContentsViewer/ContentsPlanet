(function () {
  var base = {
    startup: {
      elements: ['.outlinetext-parser-output']
    },
    tex: {
      inlineMath: [['$', '$'], ['\\(', '\\)']],
      processEscapes: true,
      tags: "ams"
    },
    svg: {
      fontCache: 'global'
    }
  };

  var user = window.MathJax ? window.MathJax : {};
  user.startup || (user.startup = {})
  user.tex || (user.tex = {});
  user.svg || (user.svg = {});

  var MathJax = user;
  Object.assign(MathJax.startup, base.startup, user.startup);
  Object.assign(MathJax.tex, base.tex, user.tex);
  Object.assign(MathJax.svg, base.svg, user.svg);
  
  window.MathJax = MathJax;
  // console.log(window.MathJax);

  var script = document.createElement('script');
  script.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js';
  script.async = true;
  document.head.appendChild(script);
})();