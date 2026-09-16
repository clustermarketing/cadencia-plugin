<?php
/**
 * Plugin Name: Cadência
 * Description: Official CadêncIA integration: SEO fields (Rank Math and Yoast), safe JSON-LD schema, AI summary widget and article audio via REST API.
 * Version: 1.7.0
 * Author: Cluster (soucluster.com.br)
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cadencia
 */

if (!defined('ABSPATH')) exit;

// A extensão mudou de pasta (`seo-api-bridge` → `cadencia`) na 1.7.0, e o
// WordPress trata pasta diferente como plugin DIFERENTE: num site que já tinha a
// versão antiga, as duas ficam ativas ao mesmo tempo.
//
// Por isso TODA função e constante daqui usa o prefixo `cadencia_ext_` /
// `CADENCIA_EXT_`: a cópia antiga declara `cadencia_*` sem prefixo, e as duas
// carregando juntas redeclarariam os mesmos nomes, o que é fatal error em PHP —
// site fora do ar. Guardar o carregamento com `function_exists` NÃO resolveria:
// o WordPress carrega em ordem alfabética de caminho, então `cadencia/` vem
// ANTES, e quem redeclararia é a cópia antiga, que já está instalada no site e
// não tem como ser corrigida retroativamente.
//
// NOMES DE OPTION E CHAVES DE META continuam SEM o prefixo
// (`cadencia_redirects`, `_cadencia_jsonld`, ...): é onde os dados vivem, e
// renomeá-los faria esta versão ignorar tudo que a antiga gravou.

const CADENCIA_EXT_VERSION = '1.7.0';
const CADENCIA_EXT_JSONLD_META_KEY = '_cadencia_jsonld';
const CADENCIA_EXT_AI_SUMMARY_WIDGET_OPTION = 'cadencia_ai_summary_widget_enabled';
const CADENCIA_EXT_GOOGLE_SITE_VERIFICATION_OPTION = 'cadencia_google_site_verification';
const CADENCIA_EXT_AUDIO_URL_META_KEY = '_cadencia_audio_url';
const CADENCIA_EXT_AUDIO_DURATION_META_KEY = '_cadencia_audio_duration';
const CADENCIA_EXT_AUDIO_WIDGET_OPTION = 'cadencia_audio_widget_enabled';
// Mapa de redirects 301 aplicados pela CadêncIA: [ '/path-antigo' => post_id ].
// Guardado com autoload OFF: só é lido em request que JÁ é 404.
const CADENCIA_EXT_REDIRECTS_OPTION = 'cadencia_redirects';

function cadencia_ext_is_assoc_array($value) {
    return is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1);
}

function cadencia_ext_normalize_jsonld_text($value) {
    if (!is_scalar($value)) return '';
    return trim(wp_strip_all_tags((string) $value));
}

function cadencia_ext_normalize_faq_jsonld_item($item) {
    if (!is_array($item) || ($item['@type'] ?? '') !== 'FAQPage') return null;

    $entities = [];
    $main_entity = $item['mainEntity'] ?? [];
    if (!is_array($main_entity)) return null;

    foreach ($main_entity as $entry) {
        if (!is_array($entry)) continue;

        $question = cadencia_ext_normalize_jsonld_text($entry['name'] ?? '');
        $answer = $entry['acceptedAnswer'] ?? null;
        $answer_text = is_array($answer)
            ? cadencia_ext_normalize_jsonld_text($answer['text'] ?? '')
            : '';

        if ($question === '' || $answer_text === '') continue;

        $entities[] = [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $answer_text,
            ],
        ];
    }

    if ($entities === []) return null;

    return [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $entities,
    ];
}

function cadencia_ext_normalize_jsonld_items($decoded) {
    $items = cadencia_ext_is_assoc_array($decoded) ? [$decoded] : $decoded;
    if (!is_array($items)) return [];

    $normalized = [];
    foreach ($items as $item) {
        $faq = cadencia_ext_normalize_faq_jsonld_item($item);
        if ($faq !== null) {
            $normalized[] = $faq;
        }
    }

    return $normalized;
}

function cadencia_ext_sanitize_jsonld_meta($value) {
    if (!is_string($value) || trim($value) === '') return '';

    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) return '';

    $normalized = cadencia_ext_normalize_jsonld_items($decoded);
    if ($normalized === []) return '';

    $encoded = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : '';
}

function cadencia_ext_sanitize_audio_url($value) {
    if (!is_scalar($value)) return '';
    $value = trim((string) $value);
    if ($value === '') return '';
    if (strpos($value, 'https://') !== 0) return '';
    return esc_url_raw($value);
}

add_action('rest_api_init', function() {
    register_setting('general', CADENCIA_EXT_AI_SUMMARY_WIDGET_OPTION, [
        'type'              => 'boolean',
        'show_in_rest'      => true,
        'default'           => true,
        'sanitize_callback' => function($value) {
            return rest_sanitize_boolean($value);
        },
        'auth_callback'     => function() {
            return current_user_can('manage_options');
        },
    ]);

    register_setting('general', CADENCIA_EXT_AUDIO_WIDGET_OPTION, [
        'type'              => 'boolean',
        'show_in_rest'      => true,
        'default'           => true,
        'sanitize_callback' => function($value) {
            return rest_sanitize_boolean($value);
        },
        'auth_callback'     => function() {
            return current_user_can('manage_options');
        },
    ]);

    // 1. CAMPOS RANK MATH
    $rank_math_fields = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_twitter_use_facebook',
        'rank_math_twitter_card_type',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        'rank_math_canonical_url',
        'rank_math_robots'
    ];

    // 2. CAMPOS YOAST SEO
    // Nota: O Yoast usa o prefixo _yoast_wpseo_
    $yoast_fields = [
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        '_yoast_wpseo_canonical',
        '_yoast_wpseo_meta-robots-noindex',
        '_yoast_wpseo_meta-robots-nofollow'
    ];

    $all_fields = array_merge($rank_math_fields, $yoast_fields);

    foreach ($all_fields as $field) {
        register_meta('post', $field, [
            'type'           => 'string',
            'single'         => true,
            'show_in_rest'   => true,
            'auth_callback'  => function() {
                return current_user_can('edit_posts');
            }
        ]);
    }

    register_meta('post', CADENCIA_EXT_JSONLD_META_KEY, [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'cadencia_ext_sanitize_jsonld_meta',
        'auth_callback'     => function() {
            return current_user_can('edit_posts');
        }
    ]);

    register_meta('post', CADENCIA_EXT_AUDIO_URL_META_KEY, [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'cadencia_ext_sanitize_audio_url',
        'auth_callback'     => function() {
            return current_user_can('edit_posts');
        }
    ]);

    register_meta('post', CADENCIA_EXT_AUDIO_DURATION_META_KEY, [
        'type'           => 'string',
        'single'         => true,
        'show_in_rest'   => true,
        'auth_callback'  => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // Verificação de posse (Google Search Console / Bing) via meta site-wide.
    // A CadêncIA grava o token aqui; o hook wp_head injeta a meta em TODAS as
    // páginas (Google busca a home pra confirmar a posse e criar a propriedade).
    register_rest_route('cadencia/v1', '/site-verification', [
        'methods'             => 'POST',
        'callback'            => 'cadencia_ext_set_site_verification',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args'                => [
            'google' => ['type' => 'string', 'required' => false],
            'bing'   => ['type' => 'string', 'required' => false],
        ],
    ]);

    // Redirects 301 da consolidação de canibalização. Quando a CadêncIA funde
    // páginas que disputavam a mesma busca, as perdedoras vão pra lixeira e o
    // caminho antigo passa a apontar pra vencedora.
    //
    // O destino é um post ID, nunca uma URL: assim o redirect acompanha o
    // permalink se ele mudar, e um destino fora deste site é impossível de
    // representar (não há open redirect a explorar).
    register_rest_route('cadencia/v1', '/redirects', [
        [
            'methods'             => 'GET',
            'callback'            => 'cadencia_ext_list_redirects',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'cadencia_ext_set_redirects',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args'                => [
                'target_id'  => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'sources'    => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => ['type' => 'string'],
                ],
                'source_ids' => [
                    'type'     => 'array',
                    'required' => false,
                    'items'    => ['type' => 'integer'],
                ],
            ],
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'cadencia_ext_delete_redirects',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args'                => [
                'sources' => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => ['type' => 'string'],
                ],
            ],
        ],
    ]);
});

function cadencia_ext_sanitize_verification_token($value) {
    if (!is_scalar($value)) return '';
    // Token é só o atributo content da meta (base64/hex). Strip de aspas/tags.
    return trim(preg_replace('/[^A-Za-z0-9_\-=.:\/]/', '', (string) $value));
}

function cadencia_ext_set_site_verification(WP_REST_Request $request) {
    $stored = get_option(CADENCIA_EXT_GOOGLE_SITE_VERIFICATION_OPTION, []);
    if (!is_array($stored)) $stored = [];

    foreach (['google', 'bing'] as $provider) {
        $raw = $request->get_param($provider);
        if ($raw === null) continue;
        $token = cadencia_ext_sanitize_verification_token($raw);
        if ($token === '') {
            unset($stored[$provider]);
        } else {
            $stored[$provider] = $token;
        }
    }

    update_option(CADENCIA_EXT_GOOGLE_SITE_VERIFICATION_OPTION, $stored);
    return new WP_REST_Response(['ok' => true, 'providers' => array_keys($stored)], 200);
}

add_action('wp_head', function() {
    $stored = get_option(CADENCIA_EXT_GOOGLE_SITE_VERIFICATION_OPTION, []);
    if (!is_array($stored)) return;

    $meta_names = [
        'google' => 'google-site-verification',
        'bing'   => 'msvalidate.01',
    ];
    foreach ($meta_names as $provider => $meta_name) {
        $token = $stored[$provider] ?? '';
        if (!is_string($token) || $token === '') continue;
        echo "\n<meta name=\"" . esc_attr($meta_name) . "\" content=\"" . esc_attr($token) . "\" />\n";
    }
}, 1);

add_action('wp_head', function() {
    if (!is_singular('post')) return;

    $raw = get_post_meta(get_the_ID(), CADENCIA_EXT_JSONLD_META_KEY, true);
    if (!is_string($raw) || trim($raw) === '') return;

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return;

    $items = cadencia_ext_normalize_jsonld_items($decoded);
    foreach ($items as $item) {
        $json = wp_json_encode($item, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') continue;
        // JSON-LD dentro de <script>: wp_json_encode já é a sanitização correta;
        // esc_html/esc_js corromperiam o JSON e quebrariam o schema.
        echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
});

function cadencia_ext_audio_widget_enabled() {
    return (bool) get_option(CADENCIA_EXT_AUDIO_WIDGET_OPTION, true);
}

function cadencia_ext_format_audio_duration($duration) {
    if (!is_numeric($duration)) return '';
    $seconds = (int) round((float) $duration);
    if ($seconds <= 0) return '';
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function cadencia_ext_build_audio_widget($url, $duration) {
    $formatted_duration = cadencia_ext_format_audio_duration($duration);
    $title = 'Ouça este artigo' . ($formatted_duration !== '' ? ' — ' . $formatted_duration : '');

    return sprintf(
        '<section class="cadencia-audio-widget" aria-label="Ouça este artigo" style="margin:24px 0;padding:16px;border:1px solid #e5e7eb;border-radius:12px;background:#fffaf3;"><p style="margin:0 0 10px;color:#162f54;font-size:16px;font-weight:700;line-height:1.4;">%1$s</p><audio controls preload="metadata" style="width:100%%;" src="%2$s"></audio></section>',
        esc_html($title),
        esc_url($url)
    );
}

function cadencia_ext_ai_summary_widget_enabled() {
    return (bool) get_option(CADENCIA_EXT_AI_SUMMARY_WIDGET_OPTION, true);
}

function cadencia_ext_ai_summary_widget_styles() {
    return <<<'CSS'
.cadencia-ai-summary-widget,
.cadencia-ai-summary-widget * {
    box-sizing: border-box;
}
.cadencia-ai-summary-widget {
    display: block !important;
    margin: 24px 0 !important;
    padding: 0 !important;
    overflow: hidden;
    border: 1px solid #d9dedd !important;
    border-radius: 14px !important;
    background: #f5faf9 !important;
    color: #1f1f1f !important;
    font-family: inherit !important;
    box-shadow: none !important;
}
.cadencia-ai-summary-main {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 14px !important;
    padding: 18px 20px !important;
}
.cadencia-ai-summary-title,
.cadencia-ai-preferred-title,
.cadencia-ai-preferred-description {
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    text-transform: none !important;
    letter-spacing: normal !important;
}
.cadencia-ai-summary-title {
    flex: 0 0 auto;
    margin: 0 !important;
    color: #1f1f1f !important;
    font-family: inherit !important;
    font-size: 15px !important;
    font-style: normal !important;
    font-weight: 700 !important;
    line-height: 1.4 !important;
}
.cadencia-ai-summary-actions {
    display: flex !important;
    flex: 0 0 auto;
    flex-wrap: wrap !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    margin: 0 !important;
    padding: 0 !important;
}
.cadencia-ai-summary-button,
.cadencia-google-preferred-source-button {
    position: relative;
    float: none !important;
    width: auto !important;
    margin: 0 !important;
    box-sizing: border-box !important;
    cursor: pointer !important;
    text-decoration: none !important;
    text-indent: 0 !important;
    text-transform: none !important;
    letter-spacing: normal !important;
    box-shadow: none !important;
}
.cadencia-ai-summary-button {
    display: inline-flex !important;
    min-height: 38px !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 9px 13px !important;
    border: 1px solid #d9dedd !important;
    border-radius: 9px !important;
    background: #ffffff !important;
    color: #1f1f1f !important;
    font-family: inherit !important;
    font-size: 13px !important;
    font-style: normal !important;
    font-weight: 500 !important;
    line-height: 18px !important;
    white-space: nowrap !important;
    transition: background-color .15s ease, border-color .15s ease, transform .15s ease !important;
}
.cadencia-ai-summary-button:hover {
    border-color: #aeb7b4 !important;
    background: #ffffff !important;
    color: #1f1f1f !important;
    transform: translateY(-1px) !important;
}
.cadencia-ai-summary-button:focus-visible,
.cadencia-google-preferred-source-button:focus-visible {
    outline: 3px solid rgba(26, 115, 232, .28) !important;
    outline-offset: 2px !important;
}
.cadencia-ai-summary-button img,
.cadencia-google-preferred-source-button img {
    display: block !important;
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    object-fit: contain !important;
    box-shadow: none !important;
}
.cadencia-ai-summary-button img {
    width: 18px !important;
    height: 18px !important;
}
.cadencia-ai-preferred-source {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 18px !important;
    margin: 0 20px !important;
    padding: 14px 0 16px !important;
    border-top: 1px solid #d9dedd !important;
}
.cadencia-ai-preferred-copy {
    min-width: 0;
}
.cadencia-ai-preferred-title {
    margin: 0 !important;
    color: #1f1f1f !important;
    font-family: inherit !important;
    font-size: 14px !important;
    font-style: normal !important;
    font-weight: 700 !important;
    line-height: 1.4 !important;
}
.cadencia-ai-preferred-description {
    margin: 2px 0 0 !important;
    color: #5f6368 !important;
    font-family: inherit !important;
    font-size: 12px !important;
    font-style: normal !important;
    font-weight: 400 !important;
    line-height: 1.4 !important;
}
.cadencia-ai-preferred-action {
    flex: 0 0 auto;
    margin-left: auto;
}
.cadencia-google-preferred-source-button {
    display: inline-flex !important;
    min-height: 40px !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 9px 16px 9px 14px !important;
    border: 1px solid #c4c7c5 !important;
    border-radius: 100px !important;
    background: #ffffff !important;
    color: #1f1f1f !important;
    font-family: "Google Sans Text", "Google Sans", Helvetica, Arial, sans-serif !important;
    font-size: 14px !important;
    font-style: normal !important;
    font-weight: 500 !important;
    line-height: 20px !important;
    white-space: nowrap !important;
    transition: background-color .2s ease, box-shadow .2s ease !important;
}
.cadencia-google-preferred-source-button:hover {
    background: #f8f9fa !important;
    color: #1f1f1f !important;
    box-shadow: 0 1px 2px rgba(60, 64, 67, .3), 0 1px 3px 1px rgba(60, 64, 67, .15) !important;
}
.cadencia-google-preferred-source-button img {
    width: 22px !important;
    height: 22px !important;
}
@media (max-width: 760px) {
    .cadencia-ai-summary-main {
        align-items: flex-start !important;
        flex-direction: column !important;
    }
    .cadencia-ai-summary-actions {
        display: grid !important;
        width: 100% !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    .cadencia-ai-summary-button {
        width: 100% !important;
    }
}
@media (max-width: 560px) {
    .cadencia-ai-preferred-source {
        align-items: flex-start !important;
        flex-direction: column !important;
        gap: 12px !important;
    }
    .cadencia-ai-preferred-action {
        max-width: 100%;
        margin-left: 0;
    }
    .cadencia-google-preferred-source-button {
        max-width: 100% !important;
        white-space: normal !important;
        text-align: center !important;
    }
}
@media (max-width: 380px) {
    .cadencia-ai-summary-actions {
        grid-template-columns: minmax(0, 1fr) !important;
    }
}
CSS;
}

add_action('wp_enqueue_scripts', function() {
    if (!is_singular('post') || !cadencia_ext_ai_summary_widget_enabled()) return;

    wp_register_style('cadencia-ai-summary-widget', false, [], CADENCIA_EXT_VERSION);
    wp_enqueue_style('cadencia-ai-summary-widget');
    wp_add_inline_style('cadencia-ai-summary-widget', cadencia_ext_ai_summary_widget_styles());
});

function cadencia_ext_preferred_source_origin($article_url) {
    if (!is_string($article_url) || trim($article_url) === '') return '';

    $parts = wp_parse_url($article_url);
    if (!is_array($parts)) return '';

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '') return '';

    $origin = $scheme . '://' . $host;
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }

    return $origin;
}

function cadencia_ext_preferred_source_url($article_url) {
    $origin = cadencia_ext_preferred_source_origin($article_url);
    if ($origin === '') return '';

    return add_query_arg('q', $origin, 'https://www.google.com/preferences/source');
}

// Textos em <div>, nunca <p>: plugins de "siga no Google"/anúncio injetam
// antes/depois do primeiro <p> do conteúdo, e o widget é o primeiro bloco do
// post. Com <p> a injeção caía dentro do flex e esmagava o título.
function cadencia_ext_build_ai_summary_widget($article_url) {
    $prompt = rawurlencode('Resuma este artigo ' . $article_url);
    $services = [
        [
            'id' => 'chatgpt',
            'name' => 'ChatGPT',
            'url' => 'https://chat.openai.com/?q=' . $prompt,
            'logo' => plugins_url('assets/chatgpt.svg', __FILE__),
        ],
        [
            'id' => 'perplexity',
            'name' => 'Perplexity',
            'url' => 'https://www.perplexity.ai/search/new?q=' . $prompt,
            'logo' => plugins_url('assets/perplexity.svg', __FILE__),
        ],
        [
            'id' => 'grok',
            'name' => 'Grok',
            'url' => 'https://x.com/i/grok?text=' . $prompt,
            'logo' => plugins_url('assets/grok.svg', __FILE__),
        ],
        [
            'id' => 'google',
            'name' => 'Google AI',
            'url' => 'https://www.google.com/search?udm=50&aep=11&q=' . $prompt,
            'logo' => plugins_url('assets/google-ai.svg', __FILE__),
        ],
    ];

    $buttons = '';
    foreach ($services as $service) {
        $buttons .= sprintf(
            '<a class="cadencia-ai-summary-button cadencia-ai-summary-%1$s" href="%2$s" target="_blank" rel="noopener noreferrer"><img src="%3$s" alt="" width="18" height="18" loading="lazy" /><span>%4$s</span></a>',
            esc_attr($service['id']),
            esc_url($service['url']),
            esc_url($service['logo']),
            esc_html($service['name'])
        );
    }

    $preferred_source_url = cadencia_ext_preferred_source_url($article_url);
    $preferred_source = '';
    if ($preferred_source_url !== '') {
        $preferred_source = sprintf(
            '<div class="cadencia-ai-preferred-source"><div class="cadencia-ai-preferred-copy"><div class="cadencia-ai-preferred-title">%1$s</div><div class="cadencia-ai-preferred-description">%2$s</div></div><div class="cadencia-ai-preferred-action"><a class="cadencia-google-preferred-source-button" href="%3$s" target="_blank" rel="noopener noreferrer" aria-label="%4$s"><img src="%5$s" alt="" width="22" height="22" /><span>%6$s</span></a></div></div>',
            esc_html__('Quer ver mais deste site no Google?', 'cadencia'),
            esc_html__('Adicione este site às suas fontes preferidas.', 'cadencia'),
            esc_url($preferred_source_url),
            esc_attr__('Adicionar este site às Fontes Preferidas no Google', 'cadencia'),
            esc_url(plugins_url('assets/google-g.svg', __FILE__)),
            esc_html__('Adicionar a Fontes Preferidas', 'cadencia')
        );
    }

    return sprintf(
        '<section class="cadencia-ai-summary-widget" aria-label="%1$s"><div class="cadencia-ai-summary-main"><div class="cadencia-ai-summary-title">%2$s</div><div class="cadencia-ai-summary-actions">%3$s</div></div>%4$s</section>',
        esc_attr__('Resuma este artigo com IA', 'cadencia'),
        esc_html__('Resuma este artigo com IA', 'cadencia'),
        $buttons,
        $preferred_source
    );
}

add_filter('the_content', function($content) {
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) return $content;

    $blocks = '';

    if (cadencia_ext_audio_widget_enabled() && strpos($content, 'cadencia-audio-widget') === false) {
        $url = get_post_meta(get_the_ID(), CADENCIA_EXT_AUDIO_URL_META_KEY, true);
        if (is_string($url) && $url !== '') {
            $blocks .= cadencia_ext_build_audio_widget($url, get_post_meta(get_the_ID(), CADENCIA_EXT_AUDIO_DURATION_META_KEY, true));
        }
    }

    if (cadencia_ext_ai_summary_widget_enabled() && strpos($content, 'cadencia-ai-summary-widget') === false) {
        $article_url = get_permalink();
        if (is_string($article_url) && $article_url !== '') {
            $blocks .= cadencia_ext_build_ai_summary_widget($article_url);
        }
    }

    return $blocks === '' ? $content : $blocks . "\n" . $content;
}, 9);

/**
 * Normaliza um caminho para a chave do mapa de redirects: sem host, sem query,
 * sem fragmento, sem barra final, minúsculo, e sem o prefixo de instalação em
 * subdiretório.
 *
 * A MESMA normalização roda na escrita e na leitura. O backend da CadêncIA
 * aplica a equivalente em `cannibalization.match.ts` — se as duas divergirem, o
 * redirect é gravado e nunca dispara.
 */
function cadencia_ext_normalize_path($value) {
    if (!is_scalar($value)) return '';

    $path = wp_parse_url((string) $value, PHP_URL_PATH);
    if (!is_string($path) || $path === '') return '';

    // Instalação em subdiretório (e multisite por path): tira o prefixo do home
    // pra chave ficar relativa ao blog, não ao domínio.
    $home = wp_parse_url(home_url('/'), PHP_URL_PATH);
    if (is_string($home) && $home !== '/' && strpos($path, $home) === 0) {
        $path = substr($path, strlen($home) - 1);
    }

    $path = '/' . trim(rawurldecode($path), '/');
    return strtolower($path);
}

/** Lê o mapa de redirects, sempre como array. */
function cadencia_ext_get_redirects() {
    $map = get_option(CADENCIA_EXT_REDIRECTS_OPTION, []);
    return is_array($map) ? $map : [];
}

function cadencia_ext_list_redirects(WP_REST_Request $request) {
    $redirects = [];
    foreach (cadencia_ext_get_redirects() as $path => $target_id) {
        $target = get_post((int) $target_id);
        $redirects[] = [
            'from'          => (string) $path,
            'target_id'     => (int) $target_id,
            'target_url'    => $target ? get_permalink($target) : '',
            'target_status' => $target ? $target->post_status : 'missing',
        ];
    }

    return new WP_REST_Response(['redirects' => $redirects, 'total' => count($redirects)], 200);
}

function cadencia_ext_set_redirects(WP_REST_Request $request) {
    $target_id = (int) $request->get_param('target_id');
    $target = get_post($target_id);
    if (!$target || $target->post_status !== 'publish') {
        return new WP_Error(
            'cadencia_invalid_target',
            __('Destination post not found or not published.', 'cadencia'),
            ['status' => 400]
        );
    }

    $map = cadencia_ext_get_redirects();

    // Achata cadeias na ESCRITA: se algo já apontava pra uma das páginas que
    // agora vira redirect, passa a apontar direto pro destino final. A leitura
    // segue sendo uma consulta única no mapa, sem recursão e sem risco de loop.
    $source_ids = array_map('absint', (array) $request->get_param('source_ids'));
    if ($source_ids !== []) {
        foreach ($map as $path => $id) {
            if (in_array((int) $id, $source_ids, true)) {
                $map[$path] = $target_id;
            }
        }
    }

    $target_path = cadencia_ext_normalize_path(get_permalink($target));
    $applied = [];
    foreach ((array) $request->get_param('sources') as $source) {
        $path = cadencia_ext_normalize_path($source);
        // Nunca redirecionar a home, nem o destino pra ele mesmo.
        if ($path === '' || $path === '/' || $path === $target_path) continue;
        $map[$path] = $target_id;
        $applied[] = $path;
    }

    // Autoload OFF: o mapa só é lido em request que já deu 404.
    update_option(CADENCIA_EXT_REDIRECTS_OPTION, $map, false);

    return new WP_REST_Response(['ok' => true, 'applied' => $applied, 'total' => count($map)], 200);
}

function cadencia_ext_delete_redirects(WP_REST_Request $request) {
    $map = cadencia_ext_get_redirects();
    $removed = [];

    foreach ((array) $request->get_param('sources') as $source) {
        $path = cadencia_ext_normalize_path($source);
        if ($path === '' || !array_key_exists($path, $map)) continue;
        unset($map[$path]);
        $removed[] = $path;
    }

    update_option(CADENCIA_EXT_REDIRECTS_OPTION, $map, false);

    return new WP_REST_Response(['ok' => true, 'removed' => $removed, 'total' => count($map)], 200);
}

/**
 * Emite o 301 das páginas consolidadas.
 *
 * Roda em `template_redirect` e SÓ quando a query principal já não achou nada
 * (`is_404()`). Esse guard é a proteção central: um caminho errado no mapa vira
 * uma entrada morta, nunca sequestra uma URL que está no ar.
 *
 * Prioridade 11 deixa o core tentar primeiro (`redirect_canonical` e
 * `wp_old_slug_redirect` rodam em 10) e faz uma regra manual de plugin de SEO
 * ganhar da automação, que é o comportamento desejado.
 */
add_action('template_redirect', function() {
    if (!is_404()) return;

    $map = cadencia_ext_get_redirects();
    if ($map === []) return;

    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
        : '';
    $path = cadencia_ext_normalize_path($request_uri);
    if ($path === '' || empty($map[$path])) return;

    $target = get_post((int) $map[$path]);
    // Destino despublicado ou na lixeira: melhor o 404 normal do que um 301
    // permanente (que o navegador cacheia) apontando pra lugar nenhum.
    if (!$target || $target->post_status !== 'publish') return;

    $url = get_permalink($target);
    if (!is_string($url) || $url === '') return;

    // Anti-loop: destino que normaliza pro mesmo caminho pedido é descartado.
    if (cadencia_ext_normalize_path($url) === $path) return;

    // Preserva a query string original (utm_source e afins) no destino.
    $query = wp_parse_url($request_uri, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
    }

    wp_safe_redirect($url, 301, 'Cadencia');
    exit;
}, 11);

/**
 * Desativa a cópia antiga da extensão (pasta `seo-api-bridge`), que esta versão
 * substitui.
 *
 * Com o prefixo `cadencia_ext_` as duas convivem sem quebrar nada, mas conviver
 * duplica o que vai pra página: dois blocos de JSON-LD, dois widgets. Aqui a
 * antiga sai de cena sozinha no primeiro carregamento, sem o cliente precisar
 * abrir o wp-admin.
 *
 * Roda em `plugins_loaded` porque `deactivate_plugins` mora no wp-admin e não
 * está disponível durante o carregamento dos plugins. A duplicação dura,
 * portanto, só a requisição em que isto roda.
 *
 * Multisite com o plugin antigo ativado PELA REDE não é coberto: `active_plugins`
 * é por site, e desativar na rede inteira a partir de um site seria invasivo.
 * Nesse caso a remoção é manual.
 */
add_action('plugins_loaded', function() {
    $legacy = 'seo-api-bridge/seo-api-bridge.php';
    $active = (array) get_option('active_plugins', []);
    if (!in_array($legacy, $active, true)) return;

    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    // `true` = silencioso: não dispara os hooks de desativação da cópia antiga,
    // que refariam a mesma limpeza duas vezes.
    deactivate_plugins($legacy, true);
}, 0);
