const fs = require("fs");
const path = require("path");
const { deploySftp } = require("./sftp-client");

const cfg = JSON.parse(fs.readFileSync(path.join(__dirname, "deploy-service.json"), "utf8"));

const items = [
	".htaccess",
	"404.html",
	"config.json",
	"favicon.ico",
	"index.php"
];

deploySftp(cfg, items, { dryRun: false, overwrite: true })
	.catch(console.error);
