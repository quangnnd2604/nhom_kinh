<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mml-wrapper">
    <h1 class="wp-heading-inline"><?php esc_html_e( '🌐 Language Manager', 'my-multilang' ); ?></h1>
    <a href="#mml-add-form" class="page-title-action"><?php esc_html_e( '+ Add Language', 'my-multilang' ); ?></a>
    <hr class="wp-header-end">

    <?php if ( $saved_msg ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $saved_msg ); ?></p></div>
    <?php endif; ?>
    <?php if ( $deleted_msg ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $deleted_msg ); ?></p></div>
    <?php endif; ?>
    <?php if ( $error_msg ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error_msg ); ?></p></div>
    <?php endif; ?>

    <!-- Language Table -->
    <table class="wp-list-table widefat fixed striped mml-lang-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Flag', 'my-multilang' ); ?></th>
                <th><?php esc_html_e( 'Language Name', 'my-multilang' ); ?></th>
                <th><?php esc_html_e( 'Code', 'my-multilang' ); ?></th>
                <th><?php esc_html_e( 'Default', 'my-multilang' ); ?></th>
                <th><?php esc_html_e( 'English Slug', 'my-multilang' ); ?></th>
                <th><?php esc_html_e( 'Order', 'my-multilang' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'my-multilang' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $languages ) ) : ?>
            <tr><td colspan="7"><?php esc_html_e( 'No languages found.', 'my-multilang' ); ?></td></tr>
        <?php else : ?>
            <?php foreach ( $languages as $lang ) : ?>
            <tr>
                <td>
                    <?php if ( $lang->flag_id ) {
                        echo wp_get_attachment_image( (int) $lang->flag_id, [ 32, 22 ] );
                    } else {
                        echo '—';
                    } ?>
                </td>
                <td><?php echo esc_html( $lang->name ); ?></td>
                <td><code><?php echo esc_html( $lang->code ); ?></code></td>
                <td><?php echo $lang->is_default ? '<span class="mml-default-star">★</span>' : ''; ?></td>
                <td>
                    <?php if ( ! empty( $lang->use_english_slug ) ) : ?>
                        <span title="<?php esc_attr_e( 'Slugs will be generated in English', 'my-multilang' ); ?>" style="color:#2271b1;font-weight:600;">&#10003; EN</span>
                    <?php else : ?>
                        <span style="color:#aaa">&mdash;</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $lang->sort_order ); ?></td>
                <td>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'mml-languages', 'edit' => $lang->id ], admin_url( 'admin.php' ) ) ); ?>#mml-add-form">
                        <?php esc_html_e( 'Edit', 'my-multilang' ); ?>
                    </a>
                    <?php if ( ! $lang->is_default ) : ?>
                        |
                        <a href="<?php echo esc_url( wp_nonce_url(
                            add_query_arg( [ 'action' => 'mml_delete_language', 'lang_id' => $lang->id ], admin_url( 'admin-post.php' ) ),
                            'mml_delete_language'
                        ) ); ?>" class="mml-delete-link" style="color:#cc0000;">
                            <?php esc_html_e( 'Delete', 'my-multilang' ); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Add / Edit Form -->
    <div class="mml-form-box" id="mml-add-form">
        <h2><?php echo $edit_lang ? esc_html__( 'Edit Language', 'my-multilang' ) : esc_html__( 'Add Language', 'my-multilang' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mml_save_language' ); ?>
            <input type="hidden" name="action" value="mml_save_language">
            <input type="hidden" name="lang_id" value="<?php echo $edit_lang ? (int) $edit_lang->id : 0; ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="lang_name"><?php esc_html_e( 'Language Name', 'my-multilang' ); ?></label></th>
                    <td>
                        <input type="text" id="lang_name" name="lang_name" class="regular-text" required
                            value="<?php echo $edit_lang ? esc_attr( $edit_lang->name ) : ''; ?>"
                            placeholder="<?php esc_attr_e( 'e.g. Tiếng Anh', 'my-multilang' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="lang_code"><?php esc_html_e( 'Language Code', 'my-multilang' ); ?></label></th>
                    <td>
                        <input type="text" id="lang_code" name="lang_code" class="small-text" maxlength="10" required
                            value="<?php echo $edit_lang ? esc_attr( $edit_lang->code ) : ''; ?>"
                            placeholder="en">
                        <p class="description"><?php esc_html_e( 'Lowercase, e.g. en, zh, ru', 'my-multilang' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Flag Icon', 'my-multilang' ); ?></label></th>
                    <td>
                        <input type="hidden" id="flag_id" name="flag_id" value="<?php echo $edit_lang ? (int) $edit_lang->flag_id : 0; ?>">
                        <div id="mml-flag-preview">
                            <?php if ( $edit_lang && $edit_lang->flag_id ) {
                                echo wp_get_attachment_image( (int) $edit_lang->flag_id, [ 40, 28 ] );
                            } ?>
                        </div>
                        <button type="button" class="button" id="mml-select-flag">
                            <?php esc_html_e( 'Select Image', 'my-multilang' ); ?>
                        </button>
                        <button type="button" class="button mml-remove-flag" id="mml-remove-flag" style="<?php echo ( $edit_lang && $edit_lang->flag_id ) ? '' : 'display:none'; ?>">
                            <?php esc_html_e( 'Remove', 'my-multilang' ); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Default Language', 'my-multilang' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_default" value="1" <?php checked( $edit_lang && $edit_lang->is_default ); ?>>
                            <?php esc_html_e( 'Set as default language', 'my-multilang' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Use English for Slugs', 'my-multilang' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="use_english_slug" value="1" <?php checked( $edit_lang && ! empty( $edit_lang->use_english_slug ) ); ?>>
                            <?php esc_html_e( 'Generate URL slugs in English (e.g. th-contact-us) instead of native characters. Recommended for Thai, Chinese, Russian, etc.', 'my-multilang' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="sort_order"><?php esc_html_e( 'Sort Order', 'my-multilang' ); ?></label></th>
                    <td>
                        <input type="number" id="sort_order" name="sort_order" class="small-text" min="0"
                            value="<?php echo $edit_lang ? (int) $edit_lang->sort_order : 0; ?>">
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php if ( $edit_lang ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mml-languages' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'my-multilang' ); ?></a>
                <?php endif; ?>
                <button type="submit" class="button button-primary">
                    <?php echo $edit_lang ? esc_html__( 'Update Language', 'my-multilang' ) : esc_html__( 'Add Language', 'my-multilang' ); ?>
                </button>
            </p>
        </form>
    </div>
</div>
