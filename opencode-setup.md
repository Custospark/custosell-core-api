
npm install -g opencode-ai

rm -rf "$USERPROFILE/.config/opencode"
rm -rf "$USERPROFILE/.opencode"
rm -rf "$USERPROFILE/AppData/Roaming/opencode"

npm uninstall -g opencode-ai


//Deploymnt commands.

ln -s /home/u214605677/domains/staging-api.custosell.com/public /home/u214605677/domains/custosell.com/public_html/staging-api
ln -s /home/u214605677/domains/staging-api.custosell.com/storage/app/public /home/u214605677/domains/staging-api.custosell.com/public/storage

Run these on the server, in order:
Step 1 — Go to the app folder
cd ~/domains/staging-api.custosell.com
Step 2 — Fix the current divergence (one-time)
git fetch origin
git reset --hard origin/main
Step 3 — Make future pulls safe (permanent)
git config pull.ff only
Step 4 — Deploy from now on (always works)
git fetch origin && git reset --hard origin/main