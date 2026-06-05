<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('opacit_mail', 'embed');
?>
<div id="app-content"
     style="display:flex;align-items:center;justify-content:center;
            height:100%;text-align:center;padding:2em;">
    <div>
        <h2 style="margin-bottom:0.5em;">OpacIT Mail</h2>
        <?php if ($_['isOidcLogin']) { ?>
            <p style="margin-bottom:1em;color:var(--color-text-maxcontrast);">
                <?php echo($l->t('Your mail session could not be established.')); ?>
                <?php echo($l->t('Your single sign-on token may have expired.')); ?>
            </p>
            <a href="<?php p($_['reloadUrl']); ?>" class="button primary">
                <?php echo($l->t('Reload')); ?>
            </a>
            <p style="margin-top:1em;color:var(--color-text-maxcontrast);">
                <?php echo($l->t('If reloading does not help, sign out of Nextcloud and sign in again.')); ?>
            </p>
        <?php } else { ?>
            <p style="color:var(--color-text-maxcontrast);">
                <?php echo($l->t('Please sign in to Nextcloud via single sign-on to access your mail.')); ?>
            </p>
        <?php } ?>
    </div>
</div>
