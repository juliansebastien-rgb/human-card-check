<?php
/**
 * Plugin Name: Human Card Check
 * Plugin URI: https://github.com/mapage-online/human-card-check
 * Description: Human-friendly card challenge for WordPress registration forms and Ultimate Member.
 * Version: 0.2.0
 * Author: MaPage
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: human-card-check
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Human_Card_Check {
    private const VERSION = '0.2.0';
    private const TRANSIENT_PREFIX = 'caj_hc_';
    private const CHALLENGE_TTL = 10 * MINUTE_IN_SECONDS;
    private const MIN_SOLVE_SECONDS = 3;

    /** @var array<int,string> */
    private array $card_labels = [
        1 => 'as',
        2 => 'roi',
        3 => 'dame',
        4 => 'valet',
        5 => '10',
        6 => '9',
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

        add_shortcode('human_card_check_demo', [$this, 'render_demo_shortcode']);
        add_shortcode('caj_human_check_demo', [$this, 'render_demo_shortcode']);

        add_action('register_form', [$this, 'render_wp_registration_challenge']);
        add_filter('registration_errors', [$this, 'validate_wp_registration'], 20, 3);

        add_action('um_after_register_fields', [$this, 'render_um_registration_challenge']);
        add_action('um_submit_form_errors_hook_registration', [$this, 'validate_um_registration'], 20);
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

    public function render_demo_shortcode(): string {
        $message = '';

        if (
            isset($_POST['caj_demo_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['caj_demo_nonce'])), 'caj_demo_submit')
        ) {
            $result = $this->validate_request_payload();
            $message = sprintf(
                '<div class="caj-human-check__demo-result %1$s">%2$s</div>',
                $result['valid'] ? 'is-valid' : 'is-invalid',
                esc_html($result['message'])
            );
        }

        ob_start();
        ?>
        <form method="post" class="caj-human-check__demo-form">
            <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php $this->render_challenge_markup('demo'); ?>
            <?php wp_nonce_field('caj_demo_submit', 'caj_demo_nonce'); ?>
            <p><button type="submit">Test the verification</button></p>
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
            $errors->add('caj_human_check', $result['message']);
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
            UM()->form()->add_error('caj_human_check', $result['message']);
        }
    }

    private function render_challenge_markup(string $context): void {
        wp_enqueue_style('caj-human-check');
        wp_enqueue_script('caj-human-check');

        $challenge = $this->create_challenge($context);
        ?>
        <div class="caj-human-check" data-context="<?php echo esc_attr($context); ?>">
            <p class="caj-human-check__title">Vérifions que vous êtes bien un humain :-)</p>
            <p class="caj-human-check__question"><?php echo esc_html($challenge['question']); ?></p>

            <div class="caj-human-check__cards" aria-hidden="true">
                <?php foreach ($challenge['cards'] as $index => $card_id) : ?>
                    <figure class="caj-human-check__card">
                        <img
                            src="<?php echo esc_url($this->get_card_url((int) $card_id)); ?>"
                            alt=""
                            loading="lazy"
                        />
                        <figcaption><?php echo esc_html($this->position_label($index)); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>

            <div class="caj-human-check__answers" role="radiogroup" aria-label="<?php echo esc_attr($challenge['question']); ?>">
                <?php foreach ($challenge['choices'] as $choice_key => $choice_label) : ?>
                    <label class="caj-human-check__answer">
                        <input type="radio" name="caj_human_check_answer" value="<?php echo esc_attr($choice_key); ?>" required>
                        <span><?php echo esc_html($choice_label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="caj_human_check_id" value="<?php echo esc_attr($challenge['id']); ?>">
        </div>
        <?php
    }

    private function create_challenge(string $context): array {
        $cards = array_map('intval', (array) array_rand($this->card_labels, 3));
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
            'question' => sprintf('Où se trouve le %s de coeur ?', $this->card_labels[$target_card]),
            'choices' => $this->shuffle_choices([
                'left' => 'À gauche',
                'center' => 'Au centre',
                'right' => 'À droite',
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
            $choices['card_' . $card_id] = sprintf('%s de coeur', $this->card_labels[$card_id]);
        }

        return [
            'question' => 'Quelle carte est au centre ?',
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
            'question' => sprintf('Voyez-vous le %s de coeur ?', $this->card_labels[$target_card]),
            'choices' => $this->shuffle_choices([
                'yes' => 'Oui',
                'no' => 'Non',
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
            'question' => 'Combien de figures voyez-vous ?',
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
            $choices['card_' . $card_id] = sprintf('%s de coeur', $this->card_labels[$card_id]);
        }

        return [
            'question' => 'Quelle est la carte la plus forte ?',
            'choices' => $this->shuffle_choices($choices),
            'answer' => 'card_' . $highest_card,
        ];
    }

    /**
     * @return array{valid:bool,message:string}
     */
    private function validate_request_payload(): array {
        $challenge_id = isset($_POST['caj_human_check_id']) ? sanitize_text_field(wp_unslash($_POST['caj_human_check_id'])) : '';
        $submitted_answer = isset($_POST['caj_human_check_answer']) ? sanitize_text_field(wp_unslash($_POST['caj_human_check_answer'])) : '';

        if ($challenge_id === '' || $submitted_answer === '') {
            return [
                'valid' => false,
            'message' => 'Please answer the card verification challenge.',
            ];
        }

        $challenge = get_transient(self::TRANSIENT_PREFIX . $challenge_id);

        if (!is_array($challenge) || empty($challenge['answer']) || empty($challenge['created_at'])) {
            return [
                'valid' => false,
                'message' => 'The verification expired. Please try again.',
            ];
        }

        delete_transient(self::TRANSIENT_PREFIX . $challenge_id);

        $age = time() - (int) $challenge['created_at'];
        $is_correct = hash_equals((string) $challenge['answer'], $submitted_answer);

        if ($age < self::MIN_SOLVE_SECONDS) {
            return [
                'valid' => false,
                'message' => 'The verification was solved too quickly. Please try again.',
            ];
        }

        if (!$is_correct) {
            return [
                'valid' => false,
                'message' => 'The answer is not correct. Please try again.',
            ];
        }

        return [
            'valid' => true,
            'message' => 'Verification successful.',
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
        return ['Gauche', 'Centre', 'Droite'][$index] ?? '';
    }
}

(new Human_Card_Check())->boot();
