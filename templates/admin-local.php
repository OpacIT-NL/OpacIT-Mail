<?php /** @var \OCP\IL10N $l */ ?>

<?php require __DIR__ . '/setup-wizard.php'; ?>

<div class="section x2m-section x2m-section-general">
    <h2><?php p($l->t('General')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Core mail features: attachments and end-to-end encryption.')); ?>
    </p>

    <div class="x2m-grid">
        <label for="x2m-menu-title"><?php p($l->t('Menu title')); ?></label>
        <div class="x2m-field">
            <input type="text" id="x2m-menu-title" name="menu_title" maxlength="64"
                   value="<?php p($_['menu_title']); ?>"
                   placeholder="<?php p($_['menu_title_default']); ?>">
        </div>
    </div>
    <p class="settings-hint">
        <?php p($l->t('Leave empty for the default (OpacIT Mail).')); ?>
        <?php p($l->t('Native NC app menu only — not Custom Menu / side_menu. Reload after save.')); ?>
    </p>

    <div class="x2m-grid">
        <label for="x2m-attachment-limit"><?php p($l->t('Attachment size limit')); ?></label>
        <div class="x2m-field">
            <input type="number" id="x2m-attachment-limit" name="attachment_size_limit"
                   min="1" max="2048" value="<?php p($_['attachment_size_limit']); ?>">
            <span class="x2m-hint">MB</span>
        </div>
    </div>

    <p>
        <input type="checkbox" id="x2m-thumbnails" name="show_attachment_thumbnail" class="checkbox"
               <?php if ($_['show_attachment_thumbnail']) {
                    p('checked');
               } ?>>
        <label for="x2m-thumbnails"><?php p($l->t('Show attachment thumbnails')); ?></label>
    </p>
    <p>
        <input type="checkbox" id="x2m-openpgp" name="openpgp" class="checkbox"
               <?php if ($_['openpgp']) {
                    p('checked');
               } ?>>
        <label for="x2m-openpgp"><?php p($l->t('Enable OpenPGP')); ?></label>
    </p>
    <p>
        <input type="checkbox" id="x2m-gnupg" name="gnupg" class="checkbox"
               <?php if ($_['gnupg']) {
                    p('checked');
               } ?>>
        <label for="x2m-gnupg"><?php p($l->t('Enable GnuPG')); ?></label>
    </p>

    <p class="x2m-actions">
        <button type="button" id="x2m-allgemein-save" class="button primary"><?php p($l->t('Save')); ?></button>
        <span id="x2m-allgemein-status" class="x2m-status" role="status" aria-live="polite"></span>
    </p>
</div>

<div class="section x2m-section x2m-section-advanced">
    <h2><?php p($l->t('Advanced')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('SSO behavior, locale, and engine paths. Change only if you know why.')); ?>
    </p>

    <p>
        <input id="opacit_mail-nc-lang" name="opacit_mail-nc-lang" type="checkbox" class="checkbox"
            <?php if ($_['opacit_mail-nc-lang']) {
                echo 'checked="checked"';
            } ?>>
        <label for="opacit_mail-nc-lang">
            <?php p($l->t('Force Nextcloud language')); ?>
        </label>
    </p>
    <p>
        <input id="opacit_mail-debug" name="opacit_mail-debug" type="checkbox" class="checkbox"
            <?php if ($_['opacit_mail-debug']) {
                echo 'checked="checked"';
            } ?>>
        <label for="opacit_mail-debug">
            <?php p($l->t('Enable engine debug logging')); ?>
        </label>
    </p>
    <p>
        <input id="opacit_mail-debug-log" name="opacit_mail-debug-log" type="checkbox" class="checkbox"
            <?php if ($_['opacit_mail-debug-log']) {
                echo 'checked="checked"';
            } ?>>
        <label for="opacit_mail-debug-log">
            <?php p($l->t('Enable OpacIT Mail debug logging (OIDC token events, refresh)')); ?>
        </label>
    </p>

    <div class="x2m-grid">
        <label for="opacit_mail-app-path"><?php p($l->t('app_path')); ?></label>
        <div class="x2m-field">
            <input id="opacit_mail-app-path" name="opacit_mail-app-path" type="text"
                   value="<?php p($_['opacit_mail-app-path']); ?>" autocomplete="off">
        </div>
    </div>

    <p class="x2m-actions">
        <button type="button" id="x2m-advanced-save" class="button primary"><?php p($l->t('Save')); ?></button>
        <span id="x2m-advanced-status" class="x2m-status" role="status" aria-live="polite"></span>
    </p>
</div>

<div class="section x2m-section x2m-section-info">
    <h2><?php p($l->t('Info')); ?></h2>
    <div class="x2m-info">
        <img class="x2m-info-logo" src="<?php p(image_path('opacit_mail', 'logo-64x64.png')); ?>" alt="OpacIT Mail">
        <div class="x2m-info-meta">
            <div class="x2m-info-version"><?php p($_['opacit_mail_version']); ?></div>
            <div class="x2m-info-copy">2026 &copy; NK-IT Dev. <?php p($l->t('All rights reserved.')); ?></div>
            <a class="x2m-info-link"
               href="https://github.com/NK-IT-CLOUD/opacit_mail"
               target="_blank"
               rel="noopener noreferrer">
                github.com/NK-IT-CLOUD/opacit_mail
            </a>
        </div>
    </div>
</div>
