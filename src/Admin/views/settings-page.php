<?php
/**
 * Settings → Sitetus. Variables from SettingsPage::render():
 * $settings, $lastPull, $notice, $nonceDirWritable, $moduleLabels, $coreVersion, $endpoint.
 */

if (! defined('ABSPATH')) {
    exit;
}

$notices = [
    'paired' => ['success', __('The site is paired. Go back to the Sitetus panel and click "Verify connection".', 'sitetus')],
    'invalid_code' => ['error', __('That is not a valid pairing code. Copy the whole code from the Sitetus panel and paste it again.', 'sitetus')],
    'modules_saved' => ['success', __('Module selection saved.', 'sitetus')],
    'unpaired' => ['info', __('The site is disconnected. The panel can no longer read it.', 'sitetus')],
];
?>
<div class="wrap">
    <h1><?php echo esc_html__('Sitetus', 'sitetus'); ?></h1>

    <?php if ($notice !== '' && isset($notices[$notice])) : ?>
        <div class="notice notice-<?php echo esc_attr($notices[$notice][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$notice][1]); ?></p></div>
    <?php endif; ?>

    <?php if (! $nonceDirWritable) : ?>
        <div class="notice notice-error"><p><?php echo esc_html__('The plugin cannot create its own folder under wp-content (or, failing that, under uploads) to keep its replay-protection record. Fix the folder permissions before pairing.', 'sitetus'); ?></p></div>
    <?php endif; ?>

    <?php if ($settings === null) : ?>
        <h2><?php echo esc_html__('Pair this site', 'sitetus'); ?></h2>
        <p><?php echo esc_html__('In the Sitetus panel open the site, go to the Collector page and copy the pairing code. Paste it here. The code contains no secret: it tells this plugin which panel may read the site and how to verify its signature.', 'sitetus'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('sitetus_pair'); ?>
            <input type="hidden" name="action" value="sitetus_pair">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sitetus-pairing-code"><?php echo esc_html__('Pairing code', 'sitetus'); ?></label></th>
                    <td>
                        <textarea id="sitetus-pairing-code" name="pairing_code" rows="5" class="large-text code" required spellcheck="false"></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Pair', 'sitetus')); ?>
        </form>
    <?php else : ?>
        <h2><?php echo esc_html__('Connection', 'sitetus'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Status', 'sitetus'); ?></th>
                <td><strong><?php echo esc_html__('Paired', 'sitetus'); ?></strong>
                    <?php if (! empty($settings['site'])) : ?> · <?php echo esc_html((string) $settings['site']); ?><?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Project key', 'sitetus'); ?></th>
                <td><code><?php echo esc_html(substr((string) $settings['project_key'], 0, 6)).'…'; ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Endpoint', 'sitetus'); ?></th>
                <td><code><?php echo esc_html($endpoint); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Last signed request', 'sitetus'); ?></th>
                <td>
                    <?php if ($lastPull === null) : ?>
                        <?php echo esc_html__('None yet — click "Verify connection" in the panel.', 'sitetus'); ?>
                    <?php else : ?>
                        <?php echo esc_html(wp_date((string) get_option('date_format').' '.get_option('time_format'), $lastPull['at'])); ?>
                        · <?php echo (int) $lastPull['status'] === 200 ? esc_html__('answered', 'sitetus') : esc_html__('rejected (clock skew or replay)', 'sitetus'); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Collector core', 'sitetus'); ?></th>
                <td><code><?php echo esc_html($coreVersion); ?></code></td>
            </tr>
            <?php if (! empty($settings['panel'])) : ?>
            <tr>
                <th scope="row"><?php echo esc_html__('Panel', 'sitetus'); ?></th>
                <td><a href="<?php echo esc_url((string) $settings['panel']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $settings['panel']); ?></a></td>
            </tr>
            <?php endif; ?>
        </table>

        <h2><?php echo esc_html__('Modules', 'sitetus'); ?></h2>
        <p><?php echo esc_html__('The panel lists which optional read-only modules this site may run; you can narrow the selection here but not widen it.', 'sitetus'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('sitetus_modules'); ?>
            <input type="hidden" name="action" value="sitetus_modules">
            <input type="hidden" name="modules_present" value="1">
            <fieldset>
                <?php foreach ((array) ($settings['modules_allowed'] ?? []) as $module) : ?>
                    <?php if (! isset($moduleLabels[$module])) { continue; } ?>
                    <label style="display:block;margin:6px 0;">
                        <input type="checkbox" name="modules[]" value="<?php echo esc_attr($module); ?>" <?php checked(in_array($module, (array) ($settings['modules'] ?? []), true)); ?>>
                        <strong><?php echo esc_html($moduleLabels[$module]['label']); ?></strong>
                        <span class="description"> — <?php echo esc_html($moduleLabels[$module]['description']); ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (($settings['modules_allowed'] ?? []) === []) : ?>
                    <p class="description"><?php echo esc_html__('The panel enabled no optional module for this site.', 'sitetus'); ?></p>
                <?php endif; ?>
            </fieldset>
            <?php submit_button(__('Save modules', 'sitetus'), 'secondary'); ?>
        </form>

        <h2><?php echo esc_html__('Disconnect', 'sitetus'); ?></h2>
        <p><?php echo esc_html__('Removes the pairing from this site. The panel then gets a 404 from the endpoint; you can pair again with a new code at any time.', 'sitetus'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this site from the Sitetus panel?', 'sitetus')); ?>');">
            <?php wp_nonce_field('sitetus_unpair'); ?>
            <input type="hidden" name="action" value="sitetus_unpair">
            <?php submit_button(__('Disconnect', 'sitetus'), 'delete'); ?>
        </form>
    <?php endif; ?>

    <h2><?php echo esc_html__('What this plugin never does', 'sitetus'); ?></h2>
    <ul class="ul-disc">
        <li><?php echo esc_html__('It never sends anything on its own. The panel calls this site with signed requests; unsigned requests get a 404. One optional module, core file integrity, makes this site ask WordPress.org for the official checksum list while it answers such a request; no other module contacts another server.', 'sitetus'); ?></li>
        <li><?php echo esc_html__('It never reads wp-config values, database credentials, salts, user names or e-mail addresses. Configuration is reported as true/false flags only.', 'sitetus'); ?></li>
        <li><?php echo esc_html__('It never transmits file contents or log lines. Optional modules report names, dates, sizes and counters.', 'sitetus'); ?></li>
        <li><?php echo esc_html__('It never changes your content, users, posts, themes or other plugins\' data. It writes only its own configuration option, its own last-request option and its replay-protection record; deleting the plugin removes all three.', 'sitetus'); ?></li>
        <li><?php echo esc_html__('It never updates itself or runs code the panel sends; the panel cannot send code.', 'sitetus'); ?></li>
    </ul>
</div>
