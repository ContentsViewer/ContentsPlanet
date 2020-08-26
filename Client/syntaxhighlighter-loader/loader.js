
function loadSyntaxHighlighter(clientURI) {
  if (SyntaxHighlighter) {
    SyntaxHighlighter.autoloader(
      'applescript            ' + clientURI + '/syntaxhighlighter/scripts/shBrushAppleScript.js',
      'actionscript3 as3      ' + clientURI + '/syntaxhighlighter/scripts/shBrushAS3.js',
      'bash shell             ' + clientURI + '/syntaxhighlighter/scripts/shBrushBash.js',
      'coldfusion cf          ' + clientURI + '/syntaxhighlighter/scripts/shBrushColdFusion.js',
      'cpp c                  ' + clientURI + '/syntaxhighlighter/scripts/shBrushCpp.js',
      'c# c-sharp csharp      ' + clientURI + '/syntaxhighlighter/scripts/shBrushCSharp.js',
      'css                    ' + clientURI + '/syntaxhighlighter/scripts/shBrushCss.js',
      'delphi pascal          ' + clientURI + '/syntaxhighlighter/scripts/shBrushDelphi.js',
      'diff patch pas         ' + clientURI + '/syntaxhighlighter/scripts/shBrushDiff.js',
      'erl erlang             ' + clientURI + '/syntaxhighlighter/scripts/shBrushErlang.js',
      'groovy                 ' + clientURI + '/syntaxhighlighter/scripts/shBrushGroovy.js',
      'java                   ' + clientURI + '/syntaxhighlighter/scripts/shBrushJava.js',
      'jfx javafx             ' + clientURI + '/syntaxhighlighter/scripts/shBrushJavaFX.js',
      'js jscript javascript  ' + clientURI + '/syntaxhighlighter/scripts/shBrushJScript.js',
      'perl pl                ' + clientURI + '/syntaxhighlighter/scripts/shBrushPerl.js',
      'php                    ' + clientURI + '/syntaxhighlighter/scripts/shBrushPhp.js',
      'text plain             ' + clientURI + '/syntaxhighlighter/scripts/shBrushPlain.js',
      'ps powershell          ' + clientURI + '/syntaxhighlighter/scripts/shBrushPowerShell.js',
      'py python              ' + clientURI + '/syntaxhighlighter/scripts/shBrushPython.js',
      'ruby rails ror rb      ' + clientURI + '/syntaxhighlighter/scripts/shBrushRuby.js',
      'sass scss              ' + clientURI + '/syntaxhighlighter/scripts/shBrushSass.js',
      'scala                  ' + clientURI + '/syntaxhighlighter/scripts/shBrushScala.js',
      'sql                    ' + clientURI + '/syntaxhighlighter/scripts/shBrushSql.js',
      'vb vbnet               ' + clientURI + '/syntaxhighlighter/scripts/shBrushVb.js',
      'xml xhtml xslt html    ' + clientURI + '/syntaxhighlighter/scripts/shBrushXml.js'
      );
      SyntaxHighlighter.defaults['gutter'] = false;
      SyntaxHighlighter.all();
  }
}