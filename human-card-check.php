<?php
/**
 * Plugin Name: Human Card Check
 * Plugin URI: https://github.com/juliansebastien-rgb/human-card-check
 * Description: Human-friendly card challenge for WordPress registration forms and Ultimate Member.
 * Version: 0.2.1
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: human-card-check
 * Update URI: https://github.com/juliansebastien-rgb/human-card-check
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Human_Card_Check {
    private const VERSION = '0.2.1';
    private const TRANSIENT_PREFIX = 'human_card_check_';
    private const CHALLENGE_TTL = 10 * MINUTE_IN_SECONDS;
    private const MIN_SOLVE_SECONDS = 3;
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/human-card-check';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/human-card-check';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/human-card-check';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;
    private const LANGUAGE_OPTION = 'human_card_check_language';

    /** @var array<string,array<int,string>> */
    private array $card_labels = [
        'fr' => [
            1 => 'as',
            2 => 'roi',
            3 => 'dame',
            4 => 'valet',
            5 => '10',
            6 => '9',
        ],
        'en' => [
            1 => 'ace',
            2 => 'king',
            3 => 'queen',
            4 => 'jack',
            5 => '10',
            6 => '9',
        ],
        'it' => [
            1 => 'asso',
            2 => 're',
            3 => 'regina',
            4 => 'fante',
            5 => '10',
            6 => '9',
        ],
        'es' => [
            1 => 'as',
            2 => 'rey',
            3 => 'reina',
            4 => 'jota',
            5 => '10',
            6 => '9',
        ],
    ];

    /** @var array<int,int> */
    private array $card_strength = [
        1 => 6,
        2 => 5,
        3 => 4,
        4 => 3,
        5 => 2,
        6 => 1,
    ];

    public function boot(): void {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);

        add_shortcode('human_card_check_demo', [$this, 'render_demo_shortcode']);
        add_shortcode('caj_human_check_demo', [$this, 'render_demo_shortcode']);

        add_action('register_form', [$this, 'render_wp_registration_challenge']);
        add_filter('registration_errors', [$this, 'validate_wp_registration'], 20, 3);

        add_action('um_after_register_fields', [$this, 'render_um_registration_challenge']);
        add_action('um_submit_form_errors_hook_registration', [$this, 'validate_um_registration'], 20);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_github_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_update_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);
    }

    public function register_assets(): void {
        wp_register_style(
            'caj-human-check',
            plugin_dir_url(__FILE__) . 'assets/css/caj-human-check.css',
            [],
            self::VERSION
        );

        wp_register_script(
            'caj-human-check',
            plugin_dir_url(__FILE__) . 'assets/js/caj-human-check.js',
            [],
            self::VERSION,
            true
        );
    }

    public function register_settings(): void {
        register_setting(
            'human_card_check_settings',
            self::LANGUAGE_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_language_setting'],
                'default' => 'auto',
            ]
        );
    }

    public function register_settings_page(): void {
        add_options_page(
            'Human Card Check',
            'Human Card Check',
            'manage_options',
            'human-card-check',
            [$this, 'render_settings_page']
        );
    }

    public function sanitize_language_setting($value): string {
        $allowed = ['auto', 'fr', 'en', 'it', 'es'];
        $value = is_string($value) ? strtolower($value) : 'auto';

        return in_array($value, $allowed, true) ? $value : 'auto';
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $current = $this->get_language_setting();
        ?>
        <div class="wrap">
            <h1>Human Card Check</h1>
            <form method="post" action="options.php">
                <?php settings_fields('human_card_check_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="human_card_check_language">Challenge language</label>
                        </th>
                        <td>
                            <select id="human_card_check_language" name="<?php echo esc_attr(self::LANGUAGE_OPTION); ?>">
                                <option value="auto" <?php selected($current, 'auto'); ?>>Automatic (site language)</option>
                                <option value="fr" <?php selected($current, 'fr'); ?>>Francais</option>
                                <option value="en" <?php selected($current, 'en'); ?>>English</option>
                                <option value="it" <?php selected($current, 'it'); ?>>Italiano</option>
                                <option value="es" <?php selected($current, 'es'); ?>>Espanol</option>
                            </select>
                            <p class="description">Choose the language used for the card challenge on the front end.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save changes'); ?>
            </form>
        </div>
        <?php
    }

    public function render_demo_shortcode(): string {
        $message = '';

        if (
            isset($_POST['human_card_check_demo_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['human_card_check_demo_nonce'])), 'human_card_check_demo_submit')
        ) {
            $result = $this->validate_request_payload();
            $message = sprintf(
                '<div class="human-card-check__demo-result %1$s">%2$s</div>',
                $result['valid'] ? 'is-valid' : 'is-invalid',
                esc_html($result['message'])
            );
        }

        ob_start();
        ?>
        <form method="post" class="human-card-check__demo-form">
            <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php $this->render_challenge_markup('demo'); ?>
            <?php wp_nonce_field('human_card_check_demo_submit', 'human_card_check_demo_nonce'); ?>
            <p><button type="submit"><?php echo esc_html($this->t('test_button')); ?></button></p>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function render_wp_registration_challenge(): void {
        $this->render_challenge_markup('wp-register');
    }

    public function validate_wp_registration(WP_Error $errors, string $sanitized_user_login = '', string $user_email = ''): WP_Error {
        $result = $this->validate_request_payload();

        if (!$result['valid']) {
            $errors->add('human_card_check', $result['message']);
        }

        return $errors;
    }

    public function render_um_registration_challenge(): void {
        $this->render_challenge_markup('um-register');
    }

    public function validate_um_registration(): void {
        if (!function_exists('UM')) {
            return;
        }

        $result = $this->validate_request_payload();

        if (!$result['valid']) {
            UM()->form()->add_error('human_card_check', $result['message']);
        }
    }

    private function render_challenge_markup(string $context): void {
        wp_enqueue_style('caj-human-check');
        wp_enqueue_script('caj-human-check');

        $challenge = $this->create_challenge($context);
        ?>
        <div class="human-card-check" data-context="<?php echo esc_attr($context); ?>">
            <p class="human-card-check__title"><?php echo esc_html($this->t('title')); ?></p>
            <p class="human-card-check__question"><?php echo esc_html($challenge['question']); ?></p>

            <div class="human-card-check__cards" aria-hidden="true">
                <?php foreach ($challenge['cards'] as $index => $card_id) : ?>
                    <figure class="human-card-check__card">
                        <img
                            src="<?php echo esc_url($this->get_card_url((int) $card_id)); ?>"
                            alt=""
                            loading="lazy"
                        />
                        <figcaption><?php echo esc_html($this->position_label($index)); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>

            <div class="human-card-check__answers" role="radiogroup" aria-label="<?php echo esc_attr($challenge['question']); ?>">
                <?php foreach ($challenge['choices'] as $choice_key => $choice_label) : ?>
                    <label class="human-card-check__answer">
                        <input type="radio" name="human_card_check_answer" value="<?php echo esc_attr($choice_key); ?>" required>
                        <span><?php echo esc_html($choice_label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="human_card_check_id" value="<?php echo esc_attr($challenge['id']); ?>">
        </div>
        <?php
    }

    private function create_challenge(string $context): array {
        $cards = array_map('intval', (array) array_rand($this->card_strength, 3));
        shuffle($cards);

        $builders = [
            'build_position_question',
            'build_center_card_question',
            'build_presence_question',
            'build_figure_count_question',
            'build_highest_card_question',
        ];

        $builder_name = $builders[array_rand($builders)];
        $payload = $this->{$builder_name}($cards);

        $challenge = [
            'id' => wp_generate_uuid4(),
            'cards' => $cards,
            'question' => $payload['question'],
            'choices' => $payload['choices'],
            'answer' => $payload['answer'],
            'created_at' => time(),
            'context' => $context,
        ];

        set_transient(self::TRANSIENT_PREFIX . $challenge['id'], $challenge, self::CHALLENGE_TTL);

        return $challenge;
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_position_question(array $cards): array {
        $target_position = wp_rand(0, 2);
        $target_card = $cards[$target_position];

        return [
            'question' => $this->t('where_is_card', ['card' => $this->get_card_display_label($target_card)]),
            'choices' => $this->shuffle_choices([
                'left' => $this->t('left'),
                'center' => $this->t('center'),
                'right' => $this->t('right'),
            ]),
            'answer' => ['left', 'center', 'right'][$target_position],
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_center_card_question(array $cards): array {
        $choices = [];

        foreach ($cards as $card_id) {
            $choices['card_' . $card_id] = $this->get_card_display_label($card_id);
        }

        return [
            'question' => $this->t('which_center'),
            'choices' => $this->shuffle_choices($choices),
            'answer' => 'card_' . $cards[1],
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_presence_question(array $cards): array {
        $target_card = wp_rand(0, 1) === 1
            ? $cards[array_rand($cards)]
            : array_rand($this->card_labels);

        $target_card = (int) $target_card;
        $is_present = in_array($target_card, $cards, true);

        return [
            'question' => $this->t('do_you_see_card', ['card' => $this->get_card_display_label($target_card)]),
            'choices' => $this->shuffle_choices([
                'yes' => $this->t('yes'),
                'no' => $this->t('no'),
            ]),
            'answer' => $is_present ? 'yes' : 'no',
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_figure_count_question(array $cards): array {
        $figure_ids = [2, 3, 4];
        $count = 0;

        foreach ($cards as $card_id) {
            if (in_array($card_id, $figure_ids, true)) {
                $count++;
            }
        }

        return [
            'question' => $this->t('how_many_figures'),
            'choices' => $this->shuffle_choices([
                '0' => '0',
                '1' => '1',
                '2' => '2',
                '3' => '3',
            ]),
            'answer' => (string) $count,
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_highest_card_question(array $cards): array {
        $highest_card = $cards[0];

        foreach ($cards as $card_id) {
            if ($this->card_strength[$card_id] > $this->card_strength[$highest_card]) {
                $highest_card = $card_id;
            }
        }

        $choices = [];

        foreach ($cards as $card_id) {
            $choices['card_' . $card_id] = $this->get_card_display_label($card_id);
        }

        return [
            'question' => $this->t('which_highest'),
            'choices' => $this->shuffle_choices($choices),
            'answer' => 'card_' . $highest_card,
        ];
    }

    /**
     * @return array{valid:bool,message:string}
     */
    private function validate_request_payload(): array {
        $challenge_id = isset($_POST['human_card_check_id']) ? sanitize_text_field(wp_unslash($_POST['human_card_check_id'])) : '';
        $submitted_answer = isset($_POST['human_card_check_answer']) ? sanitize_text_field(wp_unslash($_POST['human_card_check_answer'])) : '';

        if ($challenge_id === '' || $submitted_answer === '') {
            return [
                'valid' => false,
                'message' => $this->t('error_answer_required'),
            ];
        }

        $challenge = get_transient(self::TRANSIENT_PREFIX . $challenge_id);

        if (!is_array($challenge) || empty($challenge['answer']) || empty($challenge['created_at'])) {
            return [
                'valid' => false,
                'message' => $this->t('error_expired'),
            ];
        }

        delete_transient(self::TRANSIENT_PREFIX . $challenge_id);

        $age = time() - (int) $challenge['created_at'];
        $is_correct = hash_equals((string) $challenge['answer'], $submitted_answer);

        if ($age < self::MIN_SOLVE_SECONDS) {
            return [
                'valid' => false,
                'message' => $this->t('error_too_fast'),
            ];
        }

        if (!$is_correct) {
            return [
                'valid' => false,
                'message' => $this->t('error_incorrect'),
            ];
        }

        return [
            'valid' => true,
            'message' => $this->t('success'),
        ];
    }

    private function get_card_url(int $card_id): string {
        return plugin_dir_url(__FILE__) . 'assets/cards/CAJ-' . $card_id . '.PNG';
    }

    /**
     * @param array<string,string> $choices
     * @return array<string,string>
     */
    private function shuffle_choices(array $choices): array {
        $keys = array_keys($choices);
        shuffle($keys);

        $shuffled = [];
        foreach ($keys as $key) {
            $shuffled[$key] = $choices[$key];
        }

        return $shuffled;
    }

    private function position_label(int $index): string {
        return [
            $this->t('left'),
            $this->t('center'),
            $this->t('right'),
        ][$index] ?? '';
    }

    public function inject_github_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release_data();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        if (version_compare(self::VERSION, $release['version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $update = (object) [
            'slug' => 'human-card-check',
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '6.8',
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];

        $transient->response[$plugin_file] = $update;

        return $transient;
    }

    public function filter_plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'human-card-check') {
            return $result;
        }

        $release = $this->get_github_release_data();

        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Human Card Check',
            'slug' => 'human-card-check',
            'version' => $release['version'],
            'author' => '<a href="https://github.com/juliansebastien-rgb">Le Labo d&#039;Azertaf</a>',
            'author_profile' => 'https://github.com/juliansebastien-rgb',
            'homepage' => self::GITHUB_REPOSITORY_URL,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => '6.8',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Human-friendly card challenge for WordPress registration forms and Ultimate Member.',
                'installation' => 'Upload the plugin, activate it, then test it on your registration forms. You can also use the [human_card_check_demo] shortcode on a test page. The challenge language can be changed in Settings > Human Card Check.',
                'changelog' => sprintf("= %s =\n* GitHub release package.\n", $release['version']),
            ],
            'banners' => [],
            'icons' => [],
        ];
    }

    public function clear_update_cache($upgrader, array $hook_extra): void {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? [];

        if (in_array(plugin_basename(__FILE__), $plugins, true)) {
            delete_transient(self::TRANSIENT_PREFIX . 'github_release');
        }
    }

    public function normalize_github_update_source(string $source, string $remote_source, $upgrader, array $hook_extra): string {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return $source;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        if (!in_array(plugin_basename(__FILE__), $plugins, true)) {
            return $source;
        }

        $normalized = trailingslashit($remote_source) . 'human-card-check';

        if ($source === $normalized || !is_dir($source)) {
            return $source;
        }

        if (@rename($source, $normalized)) {
            return $normalized;
        }

        return $source;
    }

    private function get_github_release_data(): ?array {
        $cache_key = self::TRANSIENT_PREFIX . 'github_release';
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $release = $this->request_github_release('/releases/latest');

        if (!$release) {
            $tag = $this->request_github_release('/tags');
            if (!$tag || empty($tag[0]['name'])) {
                return null;
            }

            $first_tag = $tag[0];
            $release = [
                'tag_name' => $first_tag['name'],
                'zipball_url' => self::GITHUB_API_BASE . '/zipball/' . rawurlencode($first_tag['name']),
                'html_url' => self::GITHUB_REPOSITORY_URL . '/releases/tag/' . rawurlencode($first_tag['name']),
                'published_at' => gmdate('Y-m-d H:i:s'),
                'body' => '',
            ];
        }

        if (empty($release['tag_name']) || empty($release['zipball_url'])) {
            return null;
        }

        $data = [
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'package' => (string) $release['zipball_url'],
            'url' => !empty($release['html_url']) ? (string) $release['html_url'] : self::GITHUB_REPOSITORY_URL,
            'published_at' => !empty($release['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $release['published_at'])) : gmdate('Y-m-d H:i:s'),
            'body' => !empty($release['body']) ? (string) $release['body'] : '',
        ];

        set_transient($cache_key, $data, self::UPDATE_CACHE_TTL);

        return $data;
    }

    private function request_github_release(string $path) {
        $response = wp_remote_get(
            self::GITHUB_API_BASE . $path,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Human Card Check/' . self::VERSION . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    private function get_language_setting(): string {
        $value = get_option(self::LANGUAGE_OPTION, 'auto');

        return is_string($value) ? $value : 'auto';
    }

    private function get_current_language(): string {
        $setting = $this->get_language_setting();
        if ($setting !== 'auto') {
            return $setting;
        }

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $prefix = strtolower(substr((string) $locale, 0, 2));

        return in_array($prefix, ['fr', 'en', 'it', 'es'], true) ? $prefix : 'en';
    }

    private function get_card_display_label(int $card_id): string {
        $lang = $this->get_current_language();
        $labels = $this->card_labels[$lang] ?? $this->card_labels['en'];

        return $this->t('card_of_hearts', ['card' => $labels[$card_id] ?? (string) $card_id]);
    }

    private function t(string $key, array $replacements = []): string {
        $lang = $this->get_current_language();
        $translations = $this->translations();
        $value = $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;

        foreach ($replacements as $token => $replacement) {
            $value = str_replace('{' . $token . '}', (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function translations(): array {
        return [
            'fr' => [
                'title' => 'Verifions que vous etes bien un humain :-)',
                'test_button' => 'Tester la verification',
                'left' => 'A gauche',
                'center' => 'Au centre',
                'right' => 'A droite',
                'yes' => 'Oui',
                'no' => 'Non',
                'card_of_hearts' => '{card} de coeur',
                'where_is_card' => 'Ou se trouve le {card} ?',
                'which_center' => 'Quelle carte est au centre ?',
                'do_you_see_card' => 'Voyez-vous le {card} ?',
                'how_many_figures' => 'Combien de figures voyez-vous ?',
                'which_highest' => 'Quelle est la carte la plus forte ?',
                'error_answer_required' => 'Merci de repondre a la verification par cartes.',
                'error_expired' => 'La verification a expire. Merci d essayer de nouveau.',
                'error_too_fast' => 'La verification a ete resolue trop vite. Merci de recommencer calmement.',
                'error_incorrect' => 'La reponse n est pas correcte. Merci de recommencer.',
                'success' => 'Verification reussie.',
            ],
            'en' => [
                'title' => 'Let us make sure you are human :-)',
                'test_button' => 'Test the verification',
                'left' => 'Left',
                'center' => 'Center',
                'right' => 'Right',
                'yes' => 'Yes',
                'no' => 'No',
                'card_of_hearts' => '{card} of hearts',
                'where_is_card' => 'Where is the {card}?',
                'which_center' => 'Which card is in the center?',
                'do_you_see_card' => 'Do you see the {card}?',
                'how_many_figures' => 'How many face cards do you see?',
                'which_highest' => 'Which card is the highest?',
                'error_answer_required' => 'Please answer the card verification challenge.',
                'error_expired' => 'The verification expired. Please try again.',
                'error_too_fast' => 'The verification was solved too quickly. Please try again.',
                'error_incorrect' => 'The answer is not correct. Please try again.',
                'success' => 'Verification successful.',
            ],
            'it' => [
                'title' => 'Verifichiamo che tu sia davvero umano :-)',
                'test_button' => 'Testa la verifica',
                'left' => 'A sinistra',
                'center' => 'Al centro',
                'right' => 'A destra',
                'yes' => 'Si',
                'no' => 'No',
                'card_of_hearts' => '{card} di cuori',
                'where_is_card' => 'Dove si trova il {card}?',
                'which_center' => 'Quale carta e al centro?',
                'do_you_see_card' => 'Vedi il {card}?',
                'how_many_figures' => 'Quante figure vedi?',
                'which_highest' => 'Qual e la carta piu alta?',
                'error_answer_required' => 'Rispondi alla verifica con le carte.',
                'error_expired' => 'La verifica e scaduta. Riprova.',
                'error_too_fast' => 'La verifica e stata risolta troppo velocemente. Riprova.',
                'error_incorrect' => 'La risposta non e corretta. Riprova.',
                'success' => 'Verifica completata con successo.',
            ],
            'es' => [
                'title' => 'Verifiquemos que realmente eres humano :-)',
                'test_button' => 'Probar la verificacion',
                'left' => 'A la izquierda',
                'center' => 'En el centro',
                'right' => 'A la derecha',
                'yes' => 'Si',
                'no' => 'No',
                'card_of_hearts' => '{card} de corazones',
                'where_is_card' => 'Donde esta el {card}?',
                'which_center' => 'Que carta esta en el centro?',
                'do_you_see_card' => 'Ves el {card}?',
                'how_many_figures' => 'Cuantas figuras ves?',
                'which_highest' => 'Cual es la carta mas alta?',
                'error_answer_required' => 'Responde al desafio de cartas.',
                'error_expired' => 'La verificacion ha caducado. Intentalo de nuevo.',
                'error_too_fast' => 'La verificacion se resolvio demasiado rapido. Intentalo de nuevo.',
                'error_incorrect' => 'La respuesta no es correcta. Intentalo de nuevo.',
                'success' => 'Verificacion completada con exito.',
            ],
        ];
    }
}

(new Human_Card_Check())->boot();
