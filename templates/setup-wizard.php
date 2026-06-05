<div class="section" id="opacit_mail-wizard">
    <h2><?php echo($l->t('Setup Wizard')); ?></h2>
    <h2><?php echo($l->t('Mail Server')); ?></h2>

    <h3><?php echo($l->t('IMAP')); ?></h3>
    <div class="wizard-grid wizard-protocol">
        <label for="wiz-imap-host"><?php echo($l->t('IMAP Host')); ?></label>
        <input type="text" id="wiz-imap-host" placeholder="mail.intern.domain.com">

        <label for="wiz-imap-port"><?php echo($l->t('IMAP Port')); ?></label>
        <input type="number" id="wiz-imap-port" value="143" min="1" max="65535">

        <label for="wiz-imap-ssl"><?php echo($l->t('IMAP Security')); ?></label>
        <select id="wiz-imap-ssl">
            <option value="none">None</option>
            <option value="ssl">SSL/TLS</option>
            <option value="starttls">STARTTLS</option>
        </select>

        <label for="wiz-imap-audience"><?php echo($l->t('IMAP Token Audience (optional)')); ?></label>
        <input type="text" id="wiz-imap-audience"
            placeholder="<?php echo($l->t('leave empty unless your mail server uses a different OIDC client')); ?>">
    </div>

    <h3><?php echo($l->t('SMTP')); ?></h3>
    <div class="wizard-grid wizard-protocol">
        <label for="wiz-smtp-host"><?php echo($l->t('SMTP Host')); ?></label>
        <input type="text" id="wiz-smtp-host" placeholder="<?php echo($l->t('same as IMAP')); ?>">

        <label for="wiz-smtp-port"><?php echo($l->t('SMTP Port')); ?></label>
        <input type="number" id="wiz-smtp-port" value="587" min="1" max="65535">

        <label for="wiz-smtp-ssl"><?php echo($l->t('SMTP Security')); ?></label>
        <select id="wiz-smtp-ssl">
            <option value="none">None</option>
            <option value="ssl">SSL/TLS</option>
            <option value="starttls">STARTTLS</option>
        </select>

    </div>

    <h3><?php echo($l->t('Sieve')); ?></h3>
    <div class="wizard-grid wizard-protocol">
        <div class="checkbox-row">
            <input type="checkbox" id="wiz-sieve" class="checkbox">
            <label for="wiz-sieve"><?php echo($l->t('Enable Sieve filtering')); ?></label>
        </div>

        <label for="wiz-sieve-host"><?php echo($l->t('Sieve Host')); ?></label>
        <input type="text" id="wiz-sieve-host" placeholder="<?php echo($l->t('same as IMAP')); ?>">

        <label for="wiz-sieve-port"><?php echo($l->t('Sieve Port')); ?></label>
        <input type="number" id="wiz-sieve-port" value="4190" min="1" max="65535">

        <label for="wiz-sieve-ssl"><?php echo($l->t('Sieve Security')); ?></label>
        <select id="wiz-sieve-ssl">
            <option value="none">None</option>
            <option value="ssl">SSL/TLS</option>
            <option value="starttls">STARTTLS</option>
        </select>
    </div>

    <h2><?php echo($l->t('Domain & Authentication')); ?></h2>
    <div class="wizard-grid">
        <label for="wiz-domain"><?php echo($l->t('Domain')); ?></label>
        <input type="text" id="wiz-domain" placeholder="domain.com"
            title="<?php echo($l->t('Part after @ in user email addresses')); ?>">

        <label for="wiz-auth-type"><?php echo($l->t('Authentication')); ?></label>
        <select id="wiz-auth-type">
            <option value="oauth"><?php echo($l->t('SSO / OAUTHBEARER')); ?></option>
            <option value="plain"><?php echo($l->t('Password / PLAIN')); ?></option>
        </select>

        <label for="wiz-oidc-provider"><?php echo($l->t('OIDC Provider')); ?></label>
        <select id="wiz-oidc-provider">
            <option value="user_oidc">user_oidc</option>
            <option value="oidc_login">oidc_login</option>
        </select>

    </div>

    <h2 style="margin-top:1.5em"><?php echo($l->t('Connectivity & SSO')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Checks reachability and required AUTH mechanisms on IMAP/SMTP.')); ?>
        <?php p($l->t('For SSO it also verifies OIDC apps and your SSO session.')); ?>
    </p>
    <button id="wiz-preflight-btn" class="button"><?php echo($l->t('Check connectivity')); ?></button>
    <p class="settings-hint" style="margin-top:1em">
        <?php p($l->t('OAUTHBEARER login to IMAP and SMTP with your current OIDC token.')); ?>
        <?php p($l->t('ManageSieve too when filtering is enabled above.')); ?>
    </p>
    <p class="settings-hint">
        <?php p($l->t('Test Login authenticates as your own admin account.')); ?>
        <?php p($l->t('Without a mailbox of your own it fails even if the config is correct for users.')); ?>
    </p>
    <button id="wiz-testauth-btn" class="button"><?php echo($l->t('Test SSO mail login')); ?></button>
    <div class="preflight-results" id="wiz-preflight-results" style="display:none"
        role="status" aria-live="polite"></div>
    <div class="preflight-results" id="wiz-testauth-results" style="display:none"
        role="status" aria-live="polite"></div>

    <div class="wizard-actions">
        <button id="wiz-save-btn" class="button primary"><?php echo($l->t('Save Configuration')); ?></button>
        <button id="wiz-delete-btn" class="button" style="display:none"><?php echo($l->t('Delete Domain')); ?></button>
        <span class="status-msg" id="wiz-status-msg"></span>
    </div>
</div>
