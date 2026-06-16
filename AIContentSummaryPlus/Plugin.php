<?php

namespace TypechoPlugin\AIContentSummaryPlus;

use Typecho\Common;
use Typecho\Db;
use Typecho\Http\Client;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Url;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Base\Contents;
use Widget\Contents\Post\Edit;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 基于 AI 的文章摘要插件，罗伊的二次修改版本，增加手动控制温度，是否发布时自动生成摘要选项。
 *
 * @package AIContentSummaryPlus
 * @author 罗伊
 * @version 2.0.0
 * @link https://github.com/Royapagee/AIContentSummary_Plus
 */
final class Plugin implements PluginInterface
{
    public const NAME = 'AIContentSummaryPlus';

    /**
     * 注册前台渲染钩子、编辑页字段与后台管理入口。
     */
    public static function activate(): void
    {
        if (Client::get() === null) {
            throw new PluginException(_t('需要启用 PHP cURL 扩展才能使用 AIContentSummaryPlus'));
        }

        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = self::class . '::excerpt';
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = self::class . '::content';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->write = self::class . '::write';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = self::class . '::finishPublish';
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->getDefaultFieldItems = self::class . '::addField';

        Helper::addPanel(3, self::NAME . '/template/summaries.php', _t('摘要管理'), _t('管理 AI 摘要'), 'administrator');
        Helper::addAction('summaries', Action::class);
    }

    /**
     * 卸载插件时移除后台面板与动作路由。
     */
    public static function deactivate(): void
    {
        Helper::removePanel(3, self::NAME . '/template/summaries.php');
        Helper::removeAction('summaries');
    }

    /**
     * 构建插件配置表单。
     */
    public static function config(Form $form): void
    {
        $form->addInput(
            new Text(
                'modelName',
                null,
                'gpt-4o-mini',
                _t('模型名称'),
                _t('聊天补全接口使用的模型名，例如 gpt-4o-mini。')
            )
        );

        $form->addInput(
            new Password(
                'apiKey',
                null,
                '',
                _t('API Key'),
                _t('用于调用摘要接口的密钥，留空时会保留现有值。')
            )
        );

        $form->addInput(
            (new Url(
                'apiUrl',
                null,
                '',
                _t('API 地址'),
                _t('填写接口根地址，例如 https://api.example.com/v1，也支持直接填写 /chat/completions 完整地址。')
            ))->addRule('url', _t('请填写合法的 API 地址'))
        );

        $form->addInput(
            (new Textarea(
                'prompt',
                null,
                <<<'PROMPT'
你是一名专业的文章摘要编辑。请基于输入的完整文章生成一段简体中文摘要，并严格遵守以下要求：
1. 只输出摘要正文，不要添加标题、标签、引号或解释。
2. 摘要要准确、克制、保留核心主题或内容。
3. 输出长度控制在 100 字以内。
PROMPT,
                _t('系统提示词'),
                _t('用于约束摘要风格与输出格式。')
            ))->addRule('required', _t('系统提示词不能为空'))
        );

        $form->addInput(
            (new Text(
                'temperature',
                null,
                '1.0',
                _t('接口温度 (Temperature)'),
                _t('控制生成文本的随机性，通常在 0.0 到 2.0 之间。注意部分模型限制必须为特定值（如仅允许 1.0）。')
            ))
                ->addRule('required', _t('接口温度不能为空'))
                ->addRule('isFloat', _t('接口温度必须为数字'))
        );

        $form->addInput(
            (new Text(
                'fieldName',
                null,
                'ai_summary',
                _t('摘要字段名'),
                _t('摘要保存在文章自定义字段中的字段名，仅支持字母、数字和下划线，且不能以数字开头。')
            ))
                ->addRule('required', _t('摘要字段名不能为空'))
                ->addRule('regexp', _t('摘要字段名格式不正确'), '/^[_a-zA-Z][_a-zA-Z0-9]*$/')
        );

        $form->addInput(
            new Radio(
                'finishPublishSummary',
                ['1' => _t('开启'), '0' => _t('关闭')],
                '0',
                _t('发布时自动生成'),
                _t('开启后会在文章发布或更新时为没有摘要的文章自动生成摘要。')
            )
        );

        $form->addInput(
            (new Text(
                'summaryLength',
                null,
                '100',
                _t('摘要截断长度'),
                _t('用于 excerptEx 输出时的最大字符数。')
            ))
                ->addRule('required', _t('摘要截断长度不能为空'))
                ->addRule('isInteger', _t('摘要截断长度必须为整数'))
                ->addRule('min', _t('摘要截断长度不能小于 1'), 1)
        );

        $form->addInput(
            new Radio(
                'outputSummaryInHeader',
                ['1' => _t('开启'), '0' => _t('关闭')],
                '1',
                _t('正文前置摘要'),
                _t('开启后会在文章正文顶部渲染摘要模板。')
            )
        );

        $form->addInput(
            (new Textarea(
                'summaryTemplate',
                null,
                <<<'HTML'
<aside class="ai-content-summary"><h1>摘要</h1><p>{summary}</p></aside>
HTML,
                _t('摘要模板'),
                _t('正文前置摘要模板，必须包含 {summary} 占位符。')
            ))
                ->addRule('required', _t('摘要模板不能为空'))
                ->addRule(
                    static fn(?string $template): bool => str_contains((string) $template, '{summary}'),
                    _t('摘要模板必须包含 {summary} 占位符')
                )
        );
    }

    /**
     * 插件未提供个人配置项，这里保留空实现以符合接口约定。
     */
    public static function personalConfig(Form $form): void {}

    /**
     * 在摘要存在时优先输出摘要作为 excerpt。
     */
    public static function excerpt(string $excerpt, Contents $widget): string
    {
        $settings = Options::alloc()->plugin(self::NAME);
        $summary = $widget->fields->{$settings->fieldName} ?? null;
        $summary = is_string($summary) ? trim($summary) : '';

        return $summary === ''
            ? $excerpt
            : Common::subStr($summary, 0, (int) $settings->summaryLength);
    }

    /**
     * 按配置将摘要插入正文顶部。
     */
    public static function content(string $content, Contents $widget): string
    {
        $settings = Options::alloc()->plugin(self::NAME);
        if (!$settings->outputSummaryInHeader) {
            return $content;
        }

        $summary = $widget->fields->{$settings->fieldName} ?? null;
        $summary = is_string($summary) ? trim($summary) : '';
        if ($summary === '') {
            return $content;
        }

        return str_replace(
            '{summary}',
            htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'),
            $settings->summaryTemplate
        ) . $content;
    }

    /**
     * 暂存文章更新状态，以判断是否需要重新生成摘要。
     */
    private static bool $shouldRegenerate = false;

    /**
     * 获取并初始化日志文件路径。
     */
    private static function getLogFile(): string
    {
        $logDir = __DIR__ . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        return $logDir . '/debug.log';
    }

    /**
     * 文章写入时的过滤器。
     *
     * 比较新旧文本确定内容是否发生变化，并比对提交的摘要是否与原摘要一致以保护用户的手动编辑。
     */
    public static function write(array $contents, Edit $editor): array
    {
        $settings = Options::alloc()->plugin(self::NAME);
        $logFile = self::getLogFile();
        $logMsg = sprintf(
            "[%s] write called. finishPublishSummary: %s, isAdministrator: %s\n",
            date('Y-m-d H:i:s'),
            ($settings->finishPublishSummary ?? 'null'),
            (self::isAdministrator() ? 'yes' : 'no')
        );
        @file_put_contents($logFile, $logMsg, FILE_APPEND);

        if (!$settings->finishPublishSummary || !self::isAdministrator()) {
            return $contents;
        }

        $fieldName = $settings->fieldName;
        $submittedFields = $editor->request->get('fields', []);
        
        // 检查是否启用了 AI 总结
        $enabled = isset($submittedFields[$fieldName . '_enabled']) ? (string) $submittedFields[$fieldName . '_enabled'] : '1';
        if ($enabled === '0') {
            return $contents;
        }

        $oldText = trim(str_replace(['<!--markdown-->', '<!--more-->'], '', (string) $editor->text));
        $newText = trim(str_replace(['<!--markdown-->', '<!--more-->'], '', (string) ($contents['text'] ?? '')));

        if ($oldText !== '' && $oldText !== $newText) {
            $submittedSummary = isset($submittedFields[$fieldName]) ? trim((string) $submittedFields[$fieldName]) : '';
            $oldSummary = isset($editor->fields->{$fieldName}) ? trim((string) $editor->fields->{$fieldName}) : '';

            // 如果提交的摘要值没有发生任何修改，说明没有手动干预，可以自动更新
            if ($submittedSummary === $oldSummary) {
                self::$shouldRegenerate = true;
            }
        }

        return $contents;
    }

    /**
     * 发布完成后按需生成摘要。
     */
    public static function finishPublish(array $contents, Edit $editor): void
    {
        $settings = Options::alloc()->plugin(self::NAME);
        $logFile = self::getLogFile();
        $fieldName = $settings->fieldName;
        $submittedFields = $editor->request->get('fields', []);
        $enabled = isset($submittedFields[$fieldName . '_enabled'])
            ? (string) $submittedFields[$fieldName . '_enabled']
            : (isset($editor->fields->{$fieldName . '_enabled'}) ? (string) $editor->fields->{$fieldName . '_enabled'} : '1');

        $logMsg = sprintf(
            "[%s] finishPublish called. finishPublishSummary: %s, isAdministrator: %s, enabled: %s, cid: %s, shouldRegenerate: %s\n",
            date('Y-m-d H:i:s'),
            ($settings->finishPublishSummary ?? 'null'),
            (self::isAdministrator() ? 'yes' : 'no'),
            $enabled,
            $editor->cid,
            (self::$shouldRegenerate ? 'yes' : 'no')
        );
        @file_put_contents($logFile, $logMsg, FILE_APPEND);

        if (!$settings->finishPublishSummary || !self::isAdministrator() || $enabled === '0') {
            return;
        }

        $cid = (int) $editor->cid;
        $text = (string) ($contents['text'] ?? '');
        $force = self::$shouldRegenerate;

        // 注册挂接页面完成响应后的回调，实现真正的异步（先重定向发布页面，再后台请求接口更新摘要）
        register_shutdown_function(static function () use ($cid, $text, $force, $logFile) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                Plugin::ensureSummary($cid, $text, $force);
                @file_put_contents($logFile, sprintf("[%s] async ensureSummary succeeded.\n", date('Y-m-d H:i:s')), FILE_APPEND);
            } catch (\Throwable $e) {
                $logMsgErr = sprintf(
                    "[%s] async ensureSummary failed. Error: %s\nStack trace:\n%s\n",
                    date('Y-m-d H:i:s'),
                    $e->getMessage(),
                    $e->getTraceAsString()
                );
                @file_put_contents($logFile, $logMsgErr, FILE_APPEND);
            }
        });
    }

    /**
     * 为管理员添加文章摘要字段。
     *
     * 这里注入的是“默认自定义字段”元素，最终会由 Typecho 的
     * `admin/custom-fields.php` 包装成字段名/字段值两列布局。
     */
    public static function addField(Layout $layout): void
    {
        if (!self::isAdministrator()) {
            return;
        }

        $fieldName = Options::alloc()->plugin(self::NAME)->fieldName;

        $field = new Textarea(
            $fieldName,
            null,
            null,
            _t('AI 摘要'),
            _t('手动填写后将优先作为文章摘要输出，同时跳过发布时的自动生成。')
        );
        // 自定义字段区域会把 textarea 放进 field-value 列，这里只需要控制输入框尺寸。
        $field->setInputsAttribute('style', 'width:100%;height:96px;');
        $layout->addItem($field);

        $enabled = new Radio(
            $fieldName . '_enabled',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '1',
            _t('自动 AI 总结'),
            _t('是否为此文章自动生成 AI 总结。')
        );
        $layout->addItem($enabled);
    }

    /**
     * 为没有摘要的文章生成摘要。
     *
     * 发布钩子会优先使用当前编辑器中的正文，避免重复查询数据库。
     */
    public static function ensureSummary(int $cid, string $text = '', bool $force = false): ?string
    {
        $fieldName = Options::alloc()->plugin(self::NAME)->fieldName;
        $exists = Db::get()->fetchRow(
            Db::get()->select('str_value')
                ->from('table.fields')
                ->where('cid = ? AND name = ?', $cid, $fieldName)
                ->limit(1)
        );

        if (!$force && $exists && trim((string) $exists['str_value']) !== '') {
            return null;
        }

        $summary = self::generateSummary($text !== '' ? $text : (string) self::post($cid)['text']);
        self::saveSummary($cid, $summary);

        return $summary;
    }

    /**
     * 生成并保存摘要。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     */
    public static function generateForPost(int $cid): array
    {
        $summary = self::generateSummary((string) self::post($cid)['text']);
        self::saveSummary($cid, $summary);

        $summary = trim($summary);

        return [
            'cid' => $cid,
            'summary' => $summary,
            'length' => Common::strLen($summary),
            'hasSummary' => $summary !== '',
        ];
    }

    /**
     * 手动保存或清空摘要。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     */
    public static function saveManual(int $cid, string $summary): array
    {
        // 先校验文章存在，避免为失效 cid 写入自定义字段。
        self::post($cid);
        $db = Db::get();
        $fieldName = Options::alloc()->plugin(self::NAME)->fieldName;
        $summary = trim($summary);

        if ($summary === '') {
            // 手动清空时直接删除字段，前台渲染可自然回退到默认摘要逻辑。
            $db->query(
                $db->delete('table.fields')
                    ->where('cid = ? AND name = ?', $cid, $fieldName)
            );

            return [
                'cid' => $cid,
                'summary' => '',
                'length' => 0,
                'hasSummary' => false,
            ];
        }

        self::saveSummary($cid, $summary);

        return [
            'cid' => $cid,
            'summary' => $summary,
            'length' => Common::strLen($summary),
            'hasSummary' => true,
        ];
    }

    /**
     * 判断当前用户是否为管理员。
     */
    private static function isAdministrator(): bool
    {
        return User::alloc()->pass('administrator', true);
    }

    /**
     * 调用 AI 客户端生成摘要。
     */
    private static function generateSummary(string $text): string
    {
        $settings = Options::alloc()->plugin(self::NAME);

        // 去掉编辑器标记后再送给模型，避免把控制标记也纳入摘要语义。
        $text = trim(str_replace(['<!--markdown-->', '<!--more-->'], '', $text));
        if ($text === '') {
            throw new \RuntimeException(_t('文章内容为空，无法生成摘要'));
        }

        if ($settings->modelName === '' || $settings->apiKey === '' || $settings->apiUrl === '') {
            throw new \RuntimeException(_t('请先完整配置模型名称、API Key 和 API 地址'));
        }

        $client = Client::get();
        if ($client === null) {
            throw new \RuntimeException(_t('当前环境未启用 cURL 扩展'));
        }

        $client
            ->setTimeout(30)
            ->setHeader('Authorization', 'Bearer ' . $settings->apiKey)
            ->setJson([
                'model' => $settings->modelName,
                'messages' => [
                    ['role' => 'system', 'content' => $settings->prompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'temperature' => isset($settings->temperature) && is_numeric($settings->temperature) ? (float) $settings->temperature : 1.0,
            ])
            ->send(
                // 兼容填写接口根地址和直接填写 /chat/completions 完整地址两种方式。
                str_ends_with($settings->apiUrl, '/chat/completions')
                    ? $settings->apiUrl
                    : rtrim((string) $settings->apiUrl, '/') . '/chat/completions'
            );

        try {
            $payload = json_decode($client->getResponseBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException(_t('摘要接口返回了无法解析的数据'));
        }

        $status = $client->getResponseStatus();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException((string) ($payload['error']['message'] ?? _t('摘要接口请求失败，HTTP %d', $status)), $status);
        }

        $summary = trim((string) ($payload['choices'][0]['message']['content'] ?? ''));
        if ($summary === '') {
            throw new \RuntimeException(_t('摘要接口未返回有效内容'));
        }

        return $summary;
    }

    /**
     * 读取文章正文。
     *
     * 仅允许对正式文章和文章草稿生成摘要，避免把其它内容类型误当成文章处理。
     *
     * @return array<string, mixed>
     * @throws \Typecho\Widget\Exception
     */
    private static function post(int $cid): array
    {
        $post = Db::get()->fetchRow(
            Db::get()->select('cid', 'text')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type IN ?', ['post', 'post_draft'])
                ->limit(1)
        );

        if (!$post) {
            throw new \Typecho\Widget\Exception(_t('文章不存在'), 404);
        }

        return $post;
    }

    /**
     * 保存摘要字段。
     *
     * 已存在时更新，不存在时插入，保持字段写入幂等。
     */
    private static function saveSummary(int $cid, string $summary): void
    {
        $settings = Options::alloc()->plugin(self::NAME);
        $summary = trim($summary);
        $db = Db::get();
        $exists = $db->fetchRow(
            $db->select('cid')
                ->from('table.fields')
                ->where('cid = ? AND name = ?', $cid, $settings->fieldName)
                ->limit(1)
        );

        if ($exists) {
            // 编辑、重新生成时优先更新已有字段，避免产生重复 name/cid 记录。
            $db->query(
                $db->update('table.fields')
                    ->rows([
                        'type' => 'str',
                        'str_value' => $summary,
                        'int_value' => 0,
                        'float_value' => 0,
                    ])
                    ->where('cid = ? AND name = ?', $cid, $settings->fieldName)
            );

            return;
        }

        // 首次生成或首次手动保存时插入一条新的字符串字段。
        $db->query(
            $db->insert('table.fields')
                ->rows([
                    'cid' => $cid,
                    'name' => $settings->fieldName,
                    'type' => 'str',
                    'str_value' => $summary,
                    'int_value' => 0,
                    'float_value' => 0,
                ])
        );
    }
}
