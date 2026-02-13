const fs = require("fs");
const path = require("path");
const SftpClient = require("ssh2-sftp-client");

const cfg = JSON.parse(fs.readFileSync(path.resolve("deploy.json"), "utf8"));
const sftp = new SftpClient();

const DRY_RUN = process.argv.includes("--dry");

const SYNC = [
  { local: ".htaccess", remote: ".htaccess" },
  { local: "404.html", remote: "404.html" },
  { local: "config.json", remote: "config.json" },
  { local: "favicon.ico", remote: "favicon.ico" },
  { local: "index.php", remote: "index.php" },
];


function posixJoin(...parts) {
  return parts
    .filter(Boolean)
    .join("/")
    .replace(/\\/g, "/")
    .replace(/\/+/g, "/");
}

function isDir(p) {
  return fs.statSync(p).isDirectory();
}

async function ensureRemoteDir(remoteDir) {
  const parts = remoteDir.split("/").filter(Boolean);
  let cur = remoteDir.startsWith("/") ? "/" : "";
  for (const part of parts) {
    cur = cur === "/" ? `/${part}` : `${cur}/${part}`;
    try {
      // eslint-disable-next-line no-await-in-loop
      await sftp.stat(cur);
    } catch {
      try {
        // eslint-disable-next-line no-await-in-loop
        if (!DRY_RUN) await sftp.mkdir(cur);
      } catch {
        // ignore (peut déjà exister)
      }
    }
  }
}

async function uploadFile(localFile, remoteFile) {
  await ensureRemoteDir(path.posix.dirname(remoteFile));

  if (DRY_RUN) {
    console.log(`[dry] put ${localFile} -> ${remoteFile}`);
    return;
  }
  await sftp.fastPut(localFile, remoteFile);
  console.log(`put ${localFile} -> ${remoteFile}`);
}

async function uploadDirRecursive(localDir, remoteDir) {
  const entries = fs.readdirSync(localDir, { withFileTypes: true });
  for (const ent of entries) {
    const localPath = path.join(localDir, ent.name);
    const remotePath = posixJoin(remoteDir, ent.name);

    if (ent.isDirectory()) {
      await uploadDirRecursive(localPath, remotePath);
    } else if (ent.isFile()) {
      await uploadFile(localPath, remotePath);
    }
  }
}

async function main() {
  // Connexion depuis deploy.json (login simple)
  const connectOptions = {
    host: cfg.host,
    port: cfg.port ?? 22,
    username: cfg.username,
  };

  if (cfg.password) connectOptions.password = cfg.password;

  if (cfg.privateKeyPath) {
    connectOptions.privateKey = fs.readFileSync(path.resolve(cfg.privateKeyPath));
    if (cfg.passphrase) connectOptions.passphrase = cfg.passphrase;
  }

  console.log(`SFTP deploy (dry=${DRY_RUN}) -> ${cfg.host}:${connectOptions.port}`);

  await sftp.connect(connectOptions);

  try {
    for (const item of SYNC) {
      const localPath = path.resolve(item.local);
      if (!fs.existsSync(localPath)) {
        console.warn(`SKIP missing: ${item.local}`);
        continue;
      }

      // remote final
      const remotePath = posixJoin(cfg.remoteRoot, item.remote);

      if (isDir(localPath)) {
        const targetRemoteDir = item.remote === "." ? cfg.remoteRoot : remotePath;
        console.log(`dir  ${item.local} -> ${targetRemoteDir}`);
        await uploadDirRecursive(localPath, targetRemoteDir);
      } else {
        console.log(`file ${item.local} -> ${remotePath}`);
        await uploadFile(localPath, remotePath);
      }
    }
  } finally {
    await sftp.end();
  }

  console.log("Done.");
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
