<?php
/**
 * Plugin Name:       Smart Link Rel Manager (Gerenciador de Links Rel Inteligente)
 * Description:       Gerenciador leve e moderno para atributos REL e TARGET de links internos e externos, com suporte a personalização por post/artigo e atualizações automáticas via JSON remoto.
 * Version:           1.0.0
 * Author:            Antigravity AI
 * License:           GPLv2 or later
 * Text Domain:       smart-link-rel-manager
 */

// Evita o acesso direto ao arquivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define a versão do Internal Link Juicer para compatibilidade com conectores do AdmPBNs
if ( ! defined( 'ILJ_VERSION' ) ) {
    define( 'ILJ_VERSION', '2.26.0' );
}

// URL padrão para checagem de atualizações (pode ser customizada via filtro)
if ( ! defined( 'SLRM_UPDATE_JSON_URL' ) ) {
    define( 'SLRM_UPDATE_JSON_URL', 'https://raw.githubusercontent.com/aoundigital/Links-Rel-Inteligente/main/update-info.json' );
}

class Smart_Link_Rel_Manager {

    private static $instance = null;
    private $version = '1.0.0';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Enqueue styles do Admin
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Admin Menu e Configurações
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Meta Box nos Posts
        add_action( 'add_meta_boxes', array( $this, 'add_post_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_post_meta_box_data' ) );

        // Limpeza de cache de palavras-chave ao salvar ou excluir posts
        add_action( 'save_post', array( $this, 'clear_keyword_cache' ) );
        add_action( 'deleted_post', array( $this, 'clear_keyword_cache' ) );
        
        // Limpeza de cache quando atualizado via REST API (Conectores AdmPBNs)
        add_action( 'ilj_after_keywords_update', array( $this, 'clear_keyword_cache' ) );

        // Lógica de Processamento de Links
        add_filter( 'the_content', array( $this, 'filter_content_links' ), 9999 );
        add_filter( 'the_excerpt', array( $this, 'filter_content_links' ), 9999 );

        // Motor de Atualizações Automáticas (Auto-Update)
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_updates' ) );
        add_filter( 'plugins_api', array( $this, 'get_plugin_info_modal' ), 20, 3 );
    }

    /**
     * Enqueue estilos e fontes para a página do administrador
     */
    public function enqueue_admin_assets( $hook ) {
        // Enfileira apenas nas páginas do plugin e na edição de posts
        if ( 'settings_page_smart-link-rel' === $hook || in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            wp_enqueue_style( 'google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );
            wp_enqueue_style( 'slrm-admin-css', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), $this->version );
        }
    }

    /**
     * Registra o menu nas configurações gerais
     */
    public function register_admin_menu() {
        add_submenu_page(
            'options-general.php',
            'Smart Link Rel Settings',
            'Smart Link Rel',
            'manage_options',
            'smart-link-rel',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Registra as opções globais
     */
    public function register_settings() {
        register_setting( 'slrm_settings_group', 'slrm_settings' );
    }

    /**
     * Retorna valores padrão das opções
     */
    private function get_default_settings() {
        return array(
            'apply_content' => '1',
            'apply_excerpt' => '1',
            'external_target' => '_blank',
            'external_rel' => array( 'nofollow', 'noopener', 'noreferrer' ),
            'external_rel_custom' => '',
            'internal_target' => '',
            'internal_rel' => array(),
            'internal_rel_custom' => '',
            'additional_internal_domains' => '',
            'excluded_domains' => '',
            'update_json_url' => SLRM_UPDATE_JSON_URL
        );
    }

    /**
     * Retorna uma opção específica fundida com os padrões
     */
    private function get_option( $key ) {
        $options = get_option( 'slrm_settings', array() );
        $defaults = $this->get_default_settings();
        $merged = wp_parse_args( $options, $defaults );
        return isset( $merged[$key] ) ? $merged[$key] : '';
    }

    /**
     * Renderiza a página de configurações administrativas (Design Premium)
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Exibe mensagem de sucesso se as configurações forem salvas
        if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
            echo '<div class="slrm-admin-wrap"><div class="slrm-update-success">Configurações salvas com sucesso!</div></div>';
        }

        $settings = get_option( 'slrm_settings', array() );
        $defaults = $this->get_default_settings();
        $settings = wp_parse_args( $settings, $defaults );
        ?>
        <div class="slrm-admin-wrap">
            <div class="slrm-header">
                <h1>Smart Link Rel Manager</h1>
                <p>Gerenciador profissional para otimização SEO e usabilidade de links internos e externos.</p>
                <div class="slrm-version-badge">v<?php echo esc_html( $this->version ); ?></div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'slrm_settings_group' ); ?>

                <div class="slrm-grid">
                    <!-- Coluna Principal -->
                    <div class="slrm-main-column">
                        
                        <!-- Links Externos -->
                        <div class="slrm-card">
                            <h2 class="slrm-card-title">
                                <span class="dashicons dashicons-admin-links"></span> Links Externos
                            </h2>
                            
                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_external_target">Comportamento de Abertura (Target)</label>
                                <select class="slrm-select" name="slrm_settings[external_target]" id="slrm_external_target">
                                    <option value="" <?php selected( $settings['external_target'], '' ); ?>>Manter original (Nenhum)</option>
                                    <option value="_blank" <?php selected( $settings['external_target'], '_blank' ); ?>>Abrir em nova aba (_blank)</option>
                                    <option value="_self" <?php selected( $settings['external_target'], '_self' ); ?>>Abrir na mesma aba (_self)</option>
                                </select>
                                <p class="slrm-desc">Define como os links que apontam para fora do seu site serão abertos por padrão.</p>
                            </div>

                            <div class="slrm-form-group">
                                <label class="slrm-label">Atributos REL Padrão</label>
                                <div class="slrm-checkbox-grid">
                                    <?php 
                                    $rel_options = array(
                                        'nofollow'   => 'nofollow',
                                        'noopener'   => 'noopener',
                                        'noreferrer' => 'noreferrer',
                                        'sponsored'  => 'sponsored',
                                        'ugc'        => 'ugc',
                                    );
                                    foreach ( $rel_options as $val => $label ) : 
                                        $checked = in_array( $val, (array) $settings['external_rel'] ) ? 'checked' : '';
                                    ?>
                                        <div class="slrm-checkbox-item">
                                            <input type="checkbox" name="slrm_settings[external_rel][]" value="<?php echo esc_attr( $val ); ?>" id="ext_rel_<?php echo esc_attr( $val ); ?>" <?php echo $checked; ?>>
                                            <label for="ext_rel_<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="slrm-desc">Selecione as tags de relacionamento padrão para links externos.</p>
                            </div>

                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_external_rel_custom">Atributos REL Personalizados</label>
                                <input type="text" class="slrm-input-text" name="slrm_settings[external_rel_custom]" id="slrm_external_rel_custom" value="<?php echo esc_attr( $settings['external_rel_custom'] ); ?>" placeholder="ex: external noindex">
                                <p class="slrm-desc">Insira atributos separados por espaço se precisar de alguma tag personalizada para links externos.</p>
                            </div>
                        </div>

                        <!-- Links Internos -->
                        <div class="slrm-card">
                            <h2 class="slrm-card-title">
                                <span class="dashicons dashicons-admin-home"></span> Links Internos
                            </h2>
                            
                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_internal_target">Comportamento de Abertura (Target)</label>
                                <select class="slrm-select" name="slrm_settings[internal_target]" id="slrm_internal_target">
                                    <option value="" <?php selected( $settings['internal_target'], '' ); ?>>Manter original (Nenhum)</option>
                                    <option value="_blank" <?php selected( $settings['internal_target'], '_blank' ); ?>>Abrir em nova aba (_blank)</option>
                                    <option value="_self" <?php selected( $settings['internal_target'], '_self' ); ?>>Abrir na mesma aba (_self)</option>
                                </select>
                            </div>

                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_internal_rel_custom">Atributos REL Personalizados (opcional)</label>
                                <input type="text" class="slrm-input-text" name="slrm_settings[internal_rel_custom]" id="slrm_internal_rel_custom" value="<?php echo esc_attr( $settings['internal_rel_custom'] ); ?>" placeholder="ex: follow">
                                <p class="slrm-desc">Insira qualquer atributo REL que queira forçar em links internos (ex: "follow" ou deixe em branco).</p>
                            </div>
                        </div>

                        <!-- Configurações de Filtro de Domínios -->
                        <div class="slrm-card">
                            <h2 class="slrm-card-title">
                                <span class="dashicons dashicons-admin-settings"></span> Regras de Domínios & Filtros
                            </h2>

                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_additional_internal_domains">Domínios Internos Adicionais</label>
                                <textarea class="slrm-textarea" name="slrm_settings[additional_internal_domains]" id="slrm_additional_internal_domains" placeholder="meusubdominio.site.com&#10;blogparceiro.com"><?php echo esc_textarea( $settings['additional_internal_domains'] ); ?></textarea>
                                <p class="slrm-desc">Um domínio por linha. Links apontando para esses domínios serão classificados como **internos**.</p>
                            </div>

                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_excluded_domains">Domínios Excluídos de Regras Externas</label>
                                <textarea class="slrm-textarea" name="slrm_settings[excluded_domains]" id="slrm_excluded_domains" placeholder="google.com&#10;youtube.com"><?php echo esc_textarea( $settings['excluded_domains'] ); ?></textarea>
                                <p class="slrm-desc">Um domínio por linha. Links externos correspondentes a esta lista não receberão as tags REL ou TARGET externas (serão ignorados).</p>
                            </div>
                        </div>

                        <!-- Mecanismo de Atualização -->
                        <div class="slrm-card">
                            <h2 class="slrm-card-title">
                                <span class="dashicons dashicons-update"></span> Servidor de Atualizações (Auto-Update Engine)
                            </h2>
                            <div class="slrm-form-group">
                                <label class="slrm-label" for="slrm_update_json_url">URL do arquivo JSON de Atualização</label>
                                <input type="url" class="slrm-input-text" name="slrm_settings[update_json_url]" id="slrm_update_json_url" value="<?php echo esc_url( $settings['update_json_url'] ); ?>">
                                <p class="slrm-desc">O plugin consulta este arquivo JSON para verificar novas versões. Padrão: Repositório GitHub oficial.</p>
                            </div>
                        </div>

                        <button type="submit" class="slrm-btn slrm-btn-primary">Salvar Configurações</button>
                    </div>

                    <!-- Coluna Lateral Info -->
                    <div class="slrm-sidebar-column">
                        <div class="slrm-card">
                            <h3 class="slrm-card-title"><span class="dashicons dashicons-info"></span> Informações do Plugin</h3>
                            <ul class="slrm-info-list">
                                <li>
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <div><strong>Diferenciação Inteligente:</strong> Âncoras, tel:, mailto: e links relativos são tratados sem quebras automáticas.</div>
                                </li>
                                <li>
                                    <span class="dashicons dashicons-edit"></span>
                                    <div><strong>Filtro de Artigos:</strong> Aplica-se ao conteúdo principal do post e resumos. Você também pode desativar ou customizar tudo individualmente no editor de cada post.</div>
                                </li>
                                <li>
                                    <span class="dashicons dashicons-lock"></span>
                                    <div><strong>SEO Otimizado:</strong> Fusão inteligente com os atributos REL já inseridos pelo redator sem duplicar termos.</div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Adiciona a Meta Box na edição dos Posts
     */
    public function add_post_meta_box() {
        $screens = array( 'post', 'page' );
        // Obtém outros tipos de posts públicos se necessário
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        if ( ! empty( $post_types ) ) {
            $screens = array_values( $post_types );
        }

        foreach ( $screens as $screen ) {
            add_meta_box(
                'slrm_post_settings',
                'Configurações de Smart Link Rel',
                array( $this, 'render_post_meta_box' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Renderiza a Meta Box na página de edição (Design integrado e limpo)
     */
    public function render_post_meta_box( $post ) {
        // Gera o nonce de segurança
        wp_nonce_field( 'slrm_save_meta_box_data', 'slrm_meta_box_nonce' );

        // Recupera valores atuais
        $mode = get_post_meta( $post->ID, '_slrm_post_mode', true );
        if ( ! $mode ) {
            $mode = 'global';
        }

        $target = get_post_meta( $post->ID, '_slrm_post_external_target', true );
        $rel = get_post_meta( $post->ID, '_slrm_post_external_rel', true );
        if ( ! is_array( $rel ) ) {
            $rel = array();
        }
        $rel_custom = get_post_meta( $post->ID, '_slrm_post_external_rel_custom', true );
        $keywords_val = get_post_meta( $post->ID, 'ilj_linkdefinition', true );
        $keywords = '';
        if ( is_array( $keywords_val ) ) {
            $keywords = implode( ', ', $keywords_val );
        } else if ( is_string( $keywords_val ) ) {
            $keywords = $keywords_val;
        }
        ?>
        <div class="slrm-metabox-wrap">
            <div class="slrm-metabox-row">
                <label class="slrm-metabox-label" for="slrm_post_mode">Modo de Funcionamento</label>
                <select class="slrm-select" name="slrm_post_mode" id="slrm_post_mode" onchange="toggleSlrmMetaFields(this.value)">
                    <option value="global" <?php selected( $mode, 'global' ); ?>>Usar Configurações Globais</option>
                    <option value="override" <?php selected( $mode, 'override' ); ?>>Personalizar para este artigo</option>
                    <option value="disable" <?php selected( $mode, 'disable' ); ?>>Desativar filtro neste artigo</option>
                </select>
            </div>

            <!-- Div de Opções Adicionais (visível apenas ao selecionar personalizar) -->
            <div id="slrm_override_settings" class="slrm-metabox-options <?php echo ( 'override' !== $mode ) ? 'slrm-disabled' : ''; ?>">
                <div class="slrm-metabox-row">
                    <label class="slrm-metabox-label" for="slrm_post_external_target">Abertura de Links Externos</label>
                    <select class="slrm-select" name="slrm_post_external_target" id="slrm_post_external_target">
                        <option value="" <?php selected( $target, '' ); ?>>Manter original (Nenhum)</option>
                        <option value="_blank" <?php selected( $target, '_blank' ); ?>>Abrir em nova aba (_blank)</option>
                        <option value="_self" <?php selected( $target, '_self' ); ?>>Abrir na mesma aba (_self)</option>
                    </select>
                </div>

                <div class="slrm-metabox-row">
                    <label class="slrm-metabox-label">Atributos REL para Externos</label>
                    <?php 
                    $rel_options = array(
                        'nofollow'   => 'nofollow',
                        'noopener'   => 'noopener',
                        'noreferrer' => 'noreferrer',
                        'sponsored'  => 'sponsored',
                        'ugc'        => 'ugc',
                    );
                    foreach ( $rel_options as $val => $label ) : 
                        $checked = in_array( $val, $rel ) ? 'checked' : '';
                    ?>
                        <div style="margin-bottom: 5px;">
                            <input type="checkbox" name="slrm_post_external_rel[]" value="<?php echo esc_attr( $val ); ?>" id="post_ext_rel_<?php echo esc_attr( $val ); ?>" <?php echo $checked; ?>>
                            <label for="post_ext_rel_<?php echo esc_attr( $val ); ?>" style="font-size: 13px; font-weight: 500;"><?php echo esc_html( $label ); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="slrm-metabox-row">
                    <label class="slrm-metabox-label" for="slrm_post_external_rel_custom">Atributos REL Personalizados</label>
                    <input type="text" class="slrm-input-text" name="slrm_post_external_rel_custom" id="slrm_post_external_rel_custom" value="<?php echo esc_attr( $rel_custom ); ?>" placeholder="ex: external noindex" style="padding: 6px 10px; font-size:12px;">
                </div>
            </div>

            <!-- Palavras-chave para Linkagem Automática -->
            <div class="slrm-metabox-row" style="margin-top: 15px; border-top: 1px solid var(--slrm-border); padding-top: 15px;">
                <label class="slrm-metabox-label" for="slrm_post_keywords">Palavras-chave Auto-Link</label>
                <input type="text" class="slrm-input-text" name="slrm_post_keywords" id="slrm_post_keywords" value="<?php echo esc_attr( $keywords ); ?>" placeholder="ex: pão caseiro, receita de pão" style="font-size:12px; padding: 6px 10px;">
                <p class="slrm-desc" style="margin-top: 4px; font-size:11px;">Insira palavras-chave separadas por vírgula. Outros posts que citarem estes termos criarão links automáticos para este post.</p>
            </div>
        </div>

        <script>
            function toggleSlrmMetaFields(value) {
                var el = document.getElementById('slrm_override_settings');
                if (value === 'override') {
                    el.classList.remove('slrm-disabled');
                } else {
                    el.classList.add('slrm-disabled');
                }
            }
        </script>
        <?php
    }

    /**
     * Salva as configurações de Meta Box quando o post é salvo
     */
    public function save_post_meta_box_data( $post_id ) {
        // Verifica o nonce
        if ( ! isset( $_POST['slrm_meta_box_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['slrm_meta_box_nonce'], 'slrm_save_meta_box_data' ) ) {
            return;
        }

        // Evita salvamento automático do WordPress
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Verifica permissões do usuário
        if ( isset( $_POST['post_type'] ) && 'page' === $_POST['post_type'] ) {
            if ( ! current_user_can( 'edit_page', $post_id ) ) {
                return;
            }
        } else {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
        }

        // Salva os dados
        if ( isset( $_POST['slrm_post_mode'] ) ) {
            $mode = sanitize_text_field( $_POST['slrm_post_mode'] );
            update_post_meta( $post_id, '_slrm_post_mode', $mode );
        }

        if ( isset( $_POST['slrm_post_external_target'] ) ) {
            $target = sanitize_text_field( $_POST['slrm_post_external_target'] );
            update_post_meta( $post_id, '_slrm_post_external_target', $target );
        }

        if ( isset( $_POST['slrm_post_external_rel'] ) ) {
            $rel = array_map( 'sanitize_text_field', $_POST['slrm_post_external_rel'] );
            update_post_meta( $post_id, '_slrm_post_external_rel', $rel );
        } else {
            update_post_meta( $post_id, '_slrm_post_external_rel', array() );
        }

        if ( isset( $_POST['slrm_post_external_rel_custom'] ) ) {
            $rel_custom = sanitize_text_field( $_POST['slrm_post_external_rel_custom'] );
            update_post_meta( $post_id, '_slrm_post_external_rel_custom', $rel_custom );
        }

        if ( isset( $_POST['slrm_post_keywords'] ) ) {
            $keywords_str = sanitize_text_field( $_POST['slrm_post_keywords'] );
            $keywords_array = array_filter( array_map( 'trim', explode( ',', $keywords_str ) ) );
            update_post_meta( $post_id, 'ilj_linkdefinition', $keywords_array );
            $this->clear_keyword_cache();
        }
    }

    /**
     * Limpa o cache de palavras-chave
     */
    public function clear_keyword_cache() {
        delete_transient( 'slrm_keyword_links_map' );
    }

    /**
     * Obtém o mapeamento de Palavra-chave -> URL ordenado por tamanho
     */
    private function get_keyword_links_map() {
        $map = get_transient( 'slrm_keyword_links_map' );
        if ( false === $map ) {
            $map = array();
            
            // Consulta todos os posts/páginas públicos
            $query = new WP_Query( array(
                'post_type'      => 'any',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_query'     => array(
                    array(
                        'key'     => 'ilj_linkdefinition',
                        'compare' => 'EXISTS',
                    ),
                ),
            ) );

            if ( $query->have_posts() ) {
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $pid = get_the_ID();
                    $keywords_val = get_post_meta( $pid, 'ilj_linkdefinition', true );
                    if ( ! empty( $keywords_val ) ) {
                        if ( is_array( $keywords_val ) ) {
                            $keywords = $keywords_val;
                        } else {
                            $keywords = array_filter( array_map( 'trim', explode( ',', $keywords_val ) ) );
                        }
                        $permalink = get_permalink( $pid );
                        foreach ( $keywords as $kw ) {
                            if ( ! empty( $kw ) ) {
                                $map[ strtolower( $kw ) ] = array(
                                    'url'     => $permalink,
                                    'post_id' => $pid
                                );
                            }
                        }
                    }
                }
                wp_reset_postdata();
            }

            // Ordena chaves por comprimento decrescente para processar strings maiores primeiro
            uksort( $map, function( $a, $b ) {
                return strlen( $b ) - strlen( $a );
            } );

            set_transient( 'slrm_keyword_links_map', $map, DAY_IN_SECONDS );
        }
        return $map;
    }

    /**
     * Realiza a linkagem automática inteligente baseada em palavras-chave no conteúdo
     */
    private function auto_link_content( $content, $current_post_id ) {
        $map = $this->get_keyword_links_map();
        if ( empty( $map ) ) {
            return $content;
        }

        // Rastreia posts já linkados neste artigo para evitar links repetidos
        $linked_posts = array();

        // Tokeniza o HTML em tags e nós de texto puro
        $parts = preg_split( '/(<[^>]+>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        foreach ( $map as $keyword => $info ) {
            // Evita linkar para o próprio post (self-linking)
            if ( $info['post_id'] == $current_post_id ) {
                continue;
            }

            $escaped_keyword = preg_quote( $keyword, '/' );
            // Padrão de regex compatível com acentos no idioma português (\x7f-\xff)
            $pattern = '/(?<![0-9a-zA-Z\x7f-\xff])' . $escaped_keyword . '(?![0-9a-zA-Z\x7f-\xff])/iu';

            $inside_forbidden_tag = 0;
            $total_parts = count( $parts );

            for ( $i = 0; $i < $total_parts; $i++ ) {
                $part = $parts[$i];

                // Se for tag HTML
                if ( $i % 2 !== 0 ) {
                    $tag = strtolower( $part );
                    if ( preg_match( '/^<(a|h[1-6]|code|pre|script|style)\b/i', $tag ) ) {
                        $inside_forbidden_tag++;
                    }
                    if ( preg_match( '/^<\/(a|h[1-6]|code|pre|script|style)>/i', $tag ) ) {
                        $inside_forbidden_tag = max( 0, $inside_forbidden_tag - 1 );
                    }
                    continue;
                }

                // Se for texto plano
                if ( $inside_forbidden_tag === 0 && ! empty( $part ) ) {
                    if ( in_array( $info['post_id'], $linked_posts ) ) {
                        continue;
                    }

                    $replaced_count = 0;
                    $new_part = preg_replace_callback( $pattern, function( $matches ) use ( $info, &$replaced_count ) {
                        $replaced_count++;
                        return '<a href="' . esc_url( $info['url'] ) . '">' . $matches[0] . '</a>';
                    }, $part, 1 );

                    if ( $replaced_count > 0 ) {
                        $linked_posts[] = $info['post_id'];

                        // Re-tokeniza a nova parte gerada por conter código HTML
                        $sub_parts = preg_split( '/(<[^>]+>)/is', $new_part, -1, PREG_SPLIT_DELIM_CAPTURE );
                        array_splice( $parts, $i, 1, $sub_parts );

                        $added_count = count( $sub_parts );
                        $total_parts = count( $parts );

                        // Avança ponteiro para pular as novas sub-partes
                        $i += $added_count - 1;
                    }
                }
            }
        }

        return implode( '', $parts );
    }

    /**
     * Filtra e modifica os links do conteúdo dos posts
     */
    public function filter_content_links( $content ) {
        // Apenas continuamos se for string válida
        if ( ! is_string( $content ) || empty( $content ) ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( $post_id ) {
            // Verifica o modo configurado para este post específico
            $post_mode = get_post_meta( $post_id, '_slrm_post_mode', true );
            if ( 'disable' === $post_mode ) {
                return $content;
            }
        }

        // 1. Executa a linkagem automática das palavras-chave antes de formatar os links
        if ( $post_id ) {
            $content = $this->auto_link_content( $content, $post_id );
        }

        // 2. Encontra todos os links <a> no conteúdo e aplica as regras de target/rel
        $pattern = '/<a\s+([^>]+)>(.*?)<\/a>/is';
        return preg_replace_callback( $pattern, array( $this, 'process_link' ), $content );
    }

    /**
     * Função callback que processa e altera atributos de cada tag de link <a> encontrada
     */
    public function process_link( $matches ) {
        $full_link = $matches[0];
        $atts_string = $matches[1];
        $link_content = $matches[2];

        // Extrai atributos individualmente usando regex
        preg_match_all( '/(\w+)\s*=\s*(["\'])(.*?)\2/is', $atts_string, $atts_matches );
        
        $attributes = array();
        if ( ! empty( $atts_matches[1] ) ) {
            foreach ( $atts_matches[1] as $index => $attr_name ) {
                $attributes[ strtolower( $attr_name ) ] = $atts_matches[3][$index];
            }
        }

        // Se não tiver HREF, retorna o link sem modificações
        if ( empty( $attributes['href'] ) ) {
            return $full_link;
        }

        $href = trim( $attributes['href'] );

        // Ignora âncoras internas, javascript:, mailto:, tel:, etc.
        if ( strpos( $href, '#' ) === 0 || preg_match( '/^(mailto|tel|javascript|sms|ftp):/i', $href ) ) {
            return $full_link;
        }

        // Determina se o link é interno
        $is_internal = $this->check_is_internal( $href );

        // Carrega configurações correspondentes (com suporte a override por post)
        $post_id = get_the_ID();
        $post_mode = $post_id ? get_post_meta( $post_id, '_slrm_post_mode', true ) : 'global';

        $target = '';
        $rel_to_add = array();
        $rel_custom = '';

        if ( $is_internal ) {
            // Links Internos seguem sempre a regra global
            $target = $this->get_option( 'internal_target' );
            $rel_custom = $this->get_option( 'internal_rel_custom' );
        } else {
            // Links Externos - Verifica se há exclusão de domínio
            if ( $this->is_domain_excluded( $href ) ) {
                return $full_link; // Ignora links para domínios listados na exclusão
            }

            // Links Externos normais
            if ( 'override' === $post_mode && $post_id ) {
                // Usa overrides do post
                $target = get_post_meta( $post_id, '_slrm_post_external_target', true );
                $rel_to_add = (array) get_post_meta( $post_id, '_slrm_post_external_rel', true );
                $rel_custom = get_post_meta( $post_id, '_slrm_post_external_rel_custom', true );
            } else {
                // Usa globais
                $target = $this->get_option( 'external_target' );
                $rel_to_add = (array) $this->get_option( 'external_rel' );
                $rel_custom = $this->get_option( 'external_rel_custom' );
            }
        }

        // 1. Modifica o TARGET
        if ( ! empty( $target ) ) {
            $attributes['target'] = $target;
        }

        // 2. Modifica/Funde o REL
        // Coleta rels existentes no link
        $existing_rel = array();
        if ( ! empty( $attributes['rel'] ) ) {
            $existing_rel = array_map( 'trim', explode( ' ', $attributes['rel'] ) );
        }

        // Mescla novos rels padrões
        if ( ! empty( $rel_to_add ) ) {
            $existing_rel = array_merge( $existing_rel, $rel_to_add );
        }

        // Mescla novos rels customizados
        if ( ! empty( $rel_custom ) ) {
            $custom_parts = array_map( 'trim', explode( ' ', $rel_custom ) );
            $existing_rel = array_merge( $existing_rel, $custom_parts );
        }

        // Limpa duplicados e valores vazios
        $existing_rel = array_filter( array_unique( $existing_rel ) );

        if ( ! empty( $existing_rel ) ) {
            $attributes['rel'] = implode( ' ', $existing_rel );
        }

        // Reconstrói a string de atributos HTML
        $new_atts_string = '';
        foreach ( $attributes as $name => $value ) {
            $new_atts_string .= ' ' . $name . '="' . esc_attr( $value ) . '"';
        }

        return '<a' . $new_atts_string . '>' . $link_content . '</a>';
    }

    /**
     * Verifica de forma robusta se a URL é interna ao site
     */
    private function check_is_internal( $url ) {
        // URLs relativas ou absolutas sem protocolo no início (que começam com /) são internas
        if ( substr( $url, 0, 7 ) !== 'http://'
             && substr( $url, 0, 8 ) !== 'https://'
             && substr( $url, 0, 2 ) !== '//' ) {
            return true;
        }

        // Extrai o host da URL testada
        $parsed_url = parse_url( $url );
        $host = isset( $parsed_url['host'] ) ? strtolower( $parsed_url['host'] ) : '';

        if ( empty( $host ) ) {
            return true;
        }

        // Extrai o host da home_url() do WordPress
        $parsed_home = parse_url( home_url() );
        $home_host = isset( $parsed_home['host'] ) ? strtolower( $parsed_home['host'] ) : '';

        // Ignora prefixo 'www.' na comparação
        $clean_host = preg_replace( '/^www\./', '', $host );
        $clean_home_host = preg_replace( '/^www\./', '', $home_host );

        if ( $clean_host === $clean_home_host ) {
            return true;
        }

        // Verifica domínios internos adicionais definidos pelo usuário
        $additional = $this->get_option( 'additional_internal_domains' );
        if ( ! empty( $additional ) ) {
            $domains = array_filter( array_map( 'trim', explode( "\n", $additional ) ) );
            foreach ( $domains as $domain ) {
                $clean_domain = preg_replace( '/^www\./', '', strtolower( $domain ) );
                if ( ! empty( $clean_domain ) && $clean_host === $clean_domain ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verifica se o domínio da URL está na lista de exclusão
     */
    private function is_domain_excluded( $url ) {
        $parsed_url = parse_url( $url );
        $host = isset( $parsed_url['host'] ) ? strtolower( $parsed_url['host'] ) : '';

        if ( empty( $host ) ) {
            return false;
        }

        $clean_host = preg_replace( '/^www\./', '', $host );
        $excluded_setting = $this->get_option( 'excluded_domains' );

        if ( ! empty( $excluded_setting ) ) {
            $domains = array_filter( array_map( 'trim', explode( "\n", $excluded_setting ) ) );
            foreach ( $domains as $domain ) {
                $clean_domain = preg_replace( '/^www\./', '', strtolower( $domain ) );
                if ( ! empty( $clean_domain ) && $clean_host === $clean_domain ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Motor de Atualizações Automáticas: Checa se há novas versões via arquivo JSON remoto
     */
    public function check_for_plugin_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $json_url = $this->get_option( 'update_json_url' );
        if ( empty( $json_url ) ) {
            return $transient;
        }

        // Faz o request seguro para o JSON remoto com timeout estrito de 5 segundos
        $response = wp_remote_get( $json_url, array(
            'timeout'   => 5,
            'headers'   => array(
                'Accept' => 'application/json'
            )
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return $transient;
        }

        $remote_data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! $remote_data || empty( $remote_data->version ) ) {
            return $transient;
        }

        // Se a versão remota for maior do que a versão local
        if ( version_compare( $this->version, $remote_data->version, '<' ) ) {
            $plugin_slug = plugin_basename( __FILE__ );
            
            $obj = new stdClass();
            $obj->slug = 'smart-link-rel-manager';
            $obj->plugin = $plugin_slug;
            $obj->new_version = $remote_data->version;
            $obj->url = isset( $remote_data->homepage ) ? $remote_data->homepage : '';
            $obj->package = isset( $remote_data->download_url ) ? $remote_data->download_url : '';
            $obj->tested = isset( $remote_data->tested ) ? $remote_data->tested : '';
            $obj->requires = isset( $remote_data->requires ) ? $remote_data->requires : '';
            
            $transient->response[ $plugin_slug ] = $obj;
        }

        return $transient;
    }

    /**
     * Preenche os detalhes do plugin no modal de visualização do WordPress (View Details)
     */
    public function get_plugin_info_modal( $false, $action, $args ) {
        if ( isset( $args->slug ) && 'smart-link-rel-manager' === $args->slug ) {
            $json_url = $this->get_option( 'update_json_url' );
            if ( empty( $json_url ) ) {
                return $false;
            }

            $response = wp_remote_get( $json_url, array( 'timeout' => 5 ) );
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                return $false;
            }

            $remote_data = json_decode( wp_remote_retrieve_body( $response ) );
            if ( ! $remote_data ) {
                return $false;
            }

            $plugin_slug = plugin_basename( __FILE__ );

            $info = new stdClass();
            $info->name = 'Smart Link Rel Manager';
            $info->slug = 'smart-link-rel-manager';
            $info->version = $remote_data->version;
            $info->author = 'Antigravity AI';
            $info->homepage = isset( $remote_data->homepage ) ? $remote_data->homepage : '';
            $info->download_link = isset( $remote_data->download_url ) ? $remote_data->download_url : '';
            $info->tested = isset( $remote_data->tested ) ? $remote_data->tested : '';
            $info->requires = isset( $remote_data->requires ) ? $remote_data->requires : '';
            
            $info->sections = array(
                'description'  => isset( $remote_data->description ) ? $remote_data->description : 'Gerenciador leve e profissional de tags REL e TARGET.',
                'changelog'    => isset( $remote_data->changelog ) ? $remote_data->changelog : 'Melhorias de desempenho e correções gerais.'
            );

            return $info;
        }

        return $false;
    }
}

// Inicializa o plugin
add_action( 'plugins_loaded', array( 'Smart_Link_Rel_Manager', 'get_instance' ) );
