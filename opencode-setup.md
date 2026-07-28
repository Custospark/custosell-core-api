
npm install -g opencode-ai

rm -rf "$USERPROFILE/.config/opencode"
rm -rf "$USERPROFILE/.opencode"
rm -rf "$USERPROFILE/AppData/Roaming/opencode"

npm uninstall -g opencode-ai


ln -s /home/u214605677/domains/staging-api.custosell.com/public /home/u214605677/domains/custosell.com/public_html/staging-api
ln -s /home/u214605677/domains/staging-api.custosell.com/storage/app/public /home/u214605677/domains/staging-api.custosell.com/public/storage
