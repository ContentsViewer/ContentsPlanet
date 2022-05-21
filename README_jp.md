# ContentsPlanet

[English](./README.md) | 日本語

![ContentsPlanet](http://contentsviewer.work/Master/ContentsPlanet/Images/Logo.jpg)

ContentsPlanet は, 次の三つの特徴を持つWebコンテンツ管理システム(CMS)です.

* OS標準のファイルシステムを介した他システム(Git, FTP, GitHub, GitLab, OneDrive, Google Drive, ...) との連携
* アウトラインの視認性と可読性を考慮したエディタに依存しないコンテンツ記述軽量マークアップ言語
* ディレクトリを超えたコンテンツの検索性とトピックモデルに基づいたコンテンツ管理(自動タグ付け, 自動カテゴライズ, 関連提示)

## 特徴
### 他システムとの連携
本システムのコンテンツ管理は, OS標準のファイルシステムを基本とします.
一つのコンテンツは, 一つのテキストファイルであり, コンテンツの階層は, ディレクトリで表現されます.
コンテンツのメタ情報(タグやキャッシュ)は, アクセスベースで更新され, システムを通さないファイル変更であっても,
正しく動作します. 

OS標準のファイルシステムを基本とすることにより, ファイルシステムベースの他のシステム(Git, FTP, Github, Gitlab, OneDrive, Google Drive, ...)との連携を可能にし, システムを超えたコンテンツの管理を実現します.

![他システムとの連携](http://contentsviewer.work/Master/ContentsPlanet/Images/Integration.jpg)

### エディタに依存しないアウトライン記述
文章の読みやすさ, 書きやすさの向上には, アウトラインの視認性と可読性が重要であると考えます. 
本システムでは, コンテンツの記述に, プレーンテキストの段階でアウトラインの視認性と可読性を考慮した, インデントが文章の階層構造を表す軽量マークアップ言語を採用しています.

アウトラインの視認性と可読性を上げるために, エディタのアウトライン機能の有無に関係なく, すべての標準的なエディタで使えるコンテンツの書き方ができます.

![エディタに依存しないアウトライン記述](http://contentsviewer.work/Master/ContentsPlanet/Images/OutlineEditorFree.jpg)

### ディレクトリを超えたコンテンツ管理
本システムにおいて, コンテンツは, OS標準のファイルシステムにより, ディレクトリで管理されることになります. 
そこで問題になるのが, コンテンツの検索性とトピックによるコンテンツの管理です.

コンテンツの検索性の問題では, ディレクトリによってカテゴライズされていることで, カテゴリ名を分かっている人は, ほしい情報にたどり着ける一方, 分からない人には困難です.
また, コンテンツが複数のトピックが合わさって生成される(トピックモデル)と考えると, コンテンツが一元的に管理できるとは考えられず, どれを根にとるかで, 階層関係は変化します. 

そこで, 本CMSでは, 検索性の向上に, ディレクトリを超えて, 全コンテンツを対象にあいまい検索をかけることが可能です. 
また, トピックによる管理では, トピックによる自動タグ付けと, あるコンテンツと関連したトピックを持つコンテンツの提示, トピックによる自動カテゴライズを行えます. 本CMSでは, OS標準のファイルシステムによる, ディレクトリベースの管理でありつつも, ディレクトリを超えたコンテンツの管理を行います.

![ディレクトリを超えたコンテンツ管理](http://contentsviewer.work/Master/ContentsPlanet/Images/AcrossDirectories.jpg)

## 機能一覧
* ディレクトリ，コンテンツファイルベース管理
* キャッシュ利用による速いレスポンス
* コンテンツ表示と編集
* コンテンツ検索
* トピックによるコンテンツ管理
* ユーザごとのコンテンツ管理と非公開設定
* 読み/書きやすい文章作成支援フォーマット
* ローカリゼーションへの対応
* データベース(MySQL など)を使用しない
* SSL(TLS)を使用できない環境でのある程度のセキュリティ
* クラウドストレージサービス(GitHub，GitLab，Google Drive，OneDrive，...)との連携
* プラグインによる機能拡張
* システムを通さない変更の自動保存

## 対応環境
本CMSの対応環境は以下の通りです. 無料のレンタルサーバでも動くようにしています.

* Apache HTTP Server
* PHP 7.1 以上, PHP 8.0 以上
* PHP 拡張モジュール
    * mbstring
    * openssl
    * fileinfo

## 活用事例
個人~中規模のコンテンツ(最大約1000コンテンツ)を管理することを想定しています.
以下の方にお勧めです.

* 個人利用での備忘録
* サークル, 研究室, プロジェクトなど中規模の情報共有

## ライセンス
以下のサードパーティーライブラリを除き, このプロジェクト下のすべてのスクリプトは, [BSD 3-Clause License](./LICENSE) に従います.

* Client/ace
    * BSD 3-Clause License
    * <https://github.com/ajaxorg/ace>
* Client/ace-diff
    * MIT License
    * <https://github.com/ace-diff/ace-diff>
* Client/syntaxhighlighter
    * MIT License or GNU General Public License (GPL) Version 3
    * <https://github.com/syntaxhighlighter/syntaxhighlighter>

## その他の情報
本CMSに関する, その他詳しい情報は, [ContentsPlanet](http://contentsviewer.work/Master/ContentsPlanet/ContentsPlanet) をご覧ください.
