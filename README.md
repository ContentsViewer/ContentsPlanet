![CollabCMS](http://contentsviewer.work/Master/CollabCMS/Images/Logo.png)

CollabCMS は, **コンテンツファイルとそのファイル構造をデータベースの基本** とし,
基本的なコンテンツ管理機能を持ちつつ, **高度な機能は別のアプリケーションに任せてしまう**,
ウェブコンテンツ管理システム(CMS: ContentsManagementSystem)です.

多くの CMS は, コンテンツの編集から表示, バージョン管理など高機能なものがありますが,
その分コンテンツファイルはそのシステムに強く依存してしまいます.

この CMS は, コンテンツファイルとそのファイル構造をデータベースの基本とするため,
**コンテンツファイルはシステムに依存しません**.
システムを通さずにファイルを変更, 移動, 削除しても正しく表示します.

コンテンツファイルがシステムに依存しないことから, ほかのアプリケーションによる管理も可能になります.
普段使いなれているエディタはそのまま利用可能でありますし,
バージョン管理ソフト(Git など)も使用可能です.

![一般的なCMS](http://contentsviewer.work/Master/CollabCMS/Images/GeneralCMS.png)

![CollabCMS](http://contentsviewer.work/Master/CollabCMS/Images/ThisCMS.png)

個人~中規模のコンテンツ管理を想定しています.
以下の方にお勧めです.

- 個人利用での備忘録
- サークルなど中規模の情報共有

CollabCMS の特徴は以下のとおりです.

- ディレクトリ, コンテンツファイルベース管理
- キャッシュ利用による速いレスポンス
- コンテンツ表示と編集
- コンテンツ検索
- ユーザごとのコンテンツ管理と非公開設定
- 読み/書きやすい文章作成支援フォーマット
- データベース(MySQL など)を使用しない
- SSL(TLS)を使用できない環境でのある程度のセキュリティ
- クラウドサービス(GitHub, GitLab, Google Drive, OneDrive, ...)との連携

CollabCMS の対応環境は以下のとおりです. 無料のレンタルサーバでも動くようにしています.

- Apache ウェブサーバ上で php が動作できること(php7.0.x)
- php がファイルの操作を行えること

この CMS の詳しい説明は, [CollabCMS](http://contentsviewer.work/Master/CollabCMS/CollabCMS)をご覧ください.
