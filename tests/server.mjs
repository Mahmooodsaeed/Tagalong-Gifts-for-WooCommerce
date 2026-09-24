// Starts a throwaway WordPress + WooCommerce site with WordPress Playground, with this plugin
// and the test helpers mounted. Nothing is installed on your computer; the site lives in memory.
//
// Optional environment variables, for offline runs or pinning versions:
//   WP_DIR   a local WordPress folder to use instead of downloading the latest release
//   WC_DIR   a local (unzipped) WooCommerce plugin folder to use instead of installing from wordpress.org
//   PORT     port to serve on (default 9400)
//   PHP      PHP version (default 8.3)
//   DEBUG    set to 1 to print Playground's own output
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync, cpSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import os from 'node:os';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export async function startServer({ port = Number(process.env.PORT || 9400), php = process.env.PHP || '8.3' } = {}) {
  const tmp = mkdtempSync(path.join(os.tmpdir(), 'tagalong-'));
  const wcStep = process.env.WC_DIR
    ? { step: 'activatePlugin', pluginPath: 'woocommerce/woocommerce.php' }
    : { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'woocommerce' }, options: { activate: true } };

  const blueprint = {
    preferredVersions: { php, wp: 'latest' },
    steps: [
      wcStep,
      { step: 'activatePlugin', pluginPath: 'tagalong-gifts-for-woocommerce/tagalong-gifts-for-woocommerce.php' },
      { step: 'setSiteOptions', options: { permalink_structure: '/%postname%/' } },
      { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false } },
    ],
  };
  const bpFile = path.join(tmp, 'blueprint.json');
  writeFileSync(bpFile, JSON.stringify(blueprint));

  const cli = path.join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
  const args = [
    cli, 'server',
    '--port', String(port),
    '--php', php,
    '--blueprint', bpFile,
    // --mount-dir takes host and site paths as separate arguments, so Windows drive letters work.
    '--mount-dir', root, '/wordpress/wp-content/plugins/tagalong-gifts-for-woocommerce',
    '--mount-dir', path.join(root, 'tests', 'mu-plugins'), '/wordpress/wp-content/mu-plugins',
  ];
  if (process.env.WP_DIR) {
    const site = path.join(tmp, 'wordpress');
    cpSync(process.env.WP_DIR, site, { recursive: true });
    args.push('--wordpress-install-mode', 'install-from-existing-files', '--mount-dir-before-install', site, '/wordpress');
  }
  if (process.env.WC_DIR) {
    args.push('--mount-dir', path.resolve(process.env.WC_DIR), '/wordpress/wp-content/plugins/woocommerce');
  }

  const windows = process.platform === 'win32';
  // On macOS/Linux, give Playground its own process group so stop() ends it and its workers together.
  const child = spawn(process.execPath, args, { cwd: root, stdio: ['ignore', 'pipe', 'pipe'], detached: !windows });
  let log = '';
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Playground did not start within 5 minutes.\n' + log)), 300_000);
    const onData = (d) => {
      log += d;
      if (process.env.DEBUG === '1') process.stderr.write(d);
      if (/Ready! WordPress is running/.test(log)) { clearTimeout(timer); resolve(); }
    };
    child.stdout.on('data', onData);
    child.stderr.on('data', onData);
    child.on('exit', (code) => { clearTimeout(timer); reject(new Error(`Playground exited (${code}).\n` + log)); });
  });

  const url = `http://127.0.0.1:${port}`;

  // The first requests after boot can still be redirected while WordPress finishes setting up,
  // so wait until the test helper route answers normally.
  for (let i = 0; ; i++) {
    const res = await fetch(`${url}/wp-json/tagalong-test/v1/log`, { redirect: 'manual' }).catch(() => null);
    if (res?.status === 200) break;
    if (i >= 60) throw new Error('Site did not become ready.\n' + log);
    await new Promise((r) => setTimeout(r, 2000));
  }
  const stop = () => {
    child.removeAllListeners('exit');
    try {
      if (windows) spawn('taskkill', ['/pid', String(child.pid), '/T', '/F']);
      else process.kill(-child.pid, 'SIGTERM');
    } catch {
      child.kill();
    }
    rmSync(tmp, { recursive: true, force: true });
  };
  return { url, stop, log: () => log };
}
