![CommunisCMS](http://contentsviewer.work/Home/Master/Contents/CommunisCMS/Images/Logo.png)

CommunisCMSは, __コンテンツファイルとそのファイル構造をデータベースの基本__とし,
基本的なコンテンツ管理機能を持ちつつ, __高度な機能は別のアプリケーションに任せてしまう__,
ウェブコンテンツ管理システム(CMS: ContentsManagementSystem)です.

多くのCMSは, コンテンツの編集から表示, バージョン管理など高機能なものがありますが,
その分コンテンツファイルはそのシステムに強く依存してしまいます.

このCMSは, コンテンツファイルとそのファイル構造をデータベースの基本とするため,
__コンテンツファイルはシステムに依存しません__.
システムを通さずにファイルを変更, 移動, 削除しても正しく表示します.

コンテンツファイルがシステムに依存しないことから, ほかのアプリケーションによる管理も可能になります.
普段使いなれているエディタはそのまま利用可能でありますし, 
バージョン管理ソフト(Gitなど)も使用可能です.

![一般的なCMS](http://contentsviewer.work/Home/Master/Contents/CommunisCMS/Images/GeneralCMS.png)

![CommunisCMS](http://contentsviewer.work/Home/Master/Contents/CommunisCMS/Images/ThisCMS.png)


個人~中規模のコンテンツ管理を想定しています.
以下の方にお勧めです.

* 個人利用での備忘録
* サークルなど中規模の情報共有

CommunisCMSの特徴は以下のとおりです.

* ディレクトリ, コンテンツファイルベース管理
* キャッシュ利用による速いレスポンス
* コンテンツ表示
* ブラウザでの編集
* ユーザごとのコンテンツ管理
* 読み/書きやすい文章作成支援フォーマット
* MySQLを使用できない環境
* SSL(TLS)を使用できない環境でのある程度のセキュリティ
* 非公開ユーザ設定


CommunisCMSの対応環境は以下のとおりです. 無料のレンタルサーバでも動くようにしています.

* Apacheウェブサーバ上でphpが動作できること(php7.0.x)
* phpがファイルの操作を行えること 

このCMSの詳しい説明は, [CommunisCMS](http://contentsviewer.work/?content=.%2FMaster%2FContents%2FCommunisCMS%2FCommunisCMS)をご覧ください.
