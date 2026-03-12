<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mml-wrapper">
    <h1 class="wp-heading-inline"><?php esc_html_e( '📝 String Translations', 'my-multilang' ); ?></h1>
    <button type="button" class="page-title-action" id="mml-add-string-btn"><?php esc_html_e( '+ Add String Key', 'my-multilang' ); ?></button>
    <hr class="wp-header-end">

    <?php if ( $saved_msg ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $saved_msg ); ?></p></div>
    <?php endif; ?>

    <!-- Add Key Modal -->
    <div id="mml-add-key-modal" style="display:none;" class="mml-modal-overlay">
        <div class="mml-modal">
            <h3><?php esc_html_e( 'Add New String Key', 'my-multilang' ); ?></h3>
            <label for="mml-new-key"><?php esc_html_e( 'Shortcode Key', 'my-multilang' ); ?></label>
            <input type="text" id="mml-new-key" class="regular-text" placeholder="gioi_thieu">
            <p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores only. This becomes the shortcode: [your_key]', 'my-multilang' ); ?></p>
            <p id="mml-add-key-error" style="color:red;display:none;"></p>
            <div class="mml-modal-actions">
                <button type="button" class="button" id="mml-cancel-modal"><?php esc_html_e( 'Cancel', 'my-multilang' ); ?></button>
                <button type="button" class="button button-primary" id="mml-confirm-add-key"><?php esc_html_e( 'Add Key', 'my-multilang' ); ?></button>
            </div>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mml-strings-form">
        <?php wp_nonce_field( 'mml_save_strings' ); ?>
        <input type="hidden" name="action" value="mml_save_strings">

        <div class="mml-table-scroll">
        <table class="wp-list-table widefat fixed mml-strings-table" id="mml-strings-table">
            <thead>
                <tr>
                    <th class="mml-col-key"><?php esc_html_e( 'Shortcode Key', 'my-multilang' ); ?></th>
                    <?php foreach ( $languages as $lang ) : ?>
                        <th>
                            <?php if ( $lang->flag_id ) echo wp_get_attachment_image( (int) $lang->flag_id, [ 20, 14 ] ); ?>
                            <?php echo esc_html( $lang->name ); ?>
                            <?php if ( $lang->is_default ) echo '<span class="mml-default-badge">' . esc_html__( '(default)', 'my-multilang' ) . '</span>'; ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="mml-col-delete"><?php esc_html_e( 'Delete', 'my-multilang' ); ?></th>
                </tr>
            </thead>
            <tbody id="mml-strings-tbody">
                <?php if ( empty( $strings ) ) : ?>
                    <tr id="mml-no-strings">
                        <td colspan="<?php echo count( $languages ) + 2; ?>">
                            <?php esc_html_e( 'No strings yet. Click "+ Add String Key" to create one.', 'my-multilang' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $strings as $row ) :
                        $translations = json_decode( $row->translations, true ) ?: [];
                    ?>
                        <tr data-id="<?php echo (int) $row->id; ?>">
                            <td>
                                <code>[<?php echo esc_html( $row->string_key ); ?>]</code>
                            </td>
                            <?php foreach ( $languages as $lang ) :
                                $val = $translations[ $lang->code ] ?? '';
                            ?>
                                <td>
                                    <textarea name="mml_strings[<?php echo (int) $row->id; ?>][<?php echo esc_attr( $lang->code ); ?>]" rows="3"><?php echo esc_textarea( $val ); ?></textarea>
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <button type="button" class="button mml-delete-string-btn" data-id="<?php echo (int) $row->id; ?>" title="<?php esc_attr_e( 'Delete', 'my-multilang' ); ?>">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- .mml-table-scroll -->

        <p class="submit">
            <button type="submit" class="button button-primary button-large">
                <?php esc_html_e( 'Save All Changes', 'my-multilang' ); ?>
            </button>
        </p>
    </form>

    <!-- JS template for new string row (injected by admin.js) -->
    <script type="text/template" id="mml-row-template">
        <tr data-id="{{id}}">
            <td><code>[{{key}}]</code></td>
            <?php foreach ( $languages as $lang ) : ?>
                <td><textarea name="mml_strings[{{id}}][<?php echo esc_attr( $lang->code ); ?>]" rows="3"></textarea></td>
            <?php endforeach; ?>
            <td><button type="button" class="button mml-delete-string-btn" data-id="{{id}}">🗑️</button></td>
        </tr>
    </script>
</div>
