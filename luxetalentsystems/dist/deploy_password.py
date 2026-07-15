import base64,zlib,os,subprocess

# 1. Deploy performer-view.html
pv="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"
with open('/var/www/luxe-talent/dist/performer-view.html','wb') as f:
    f.write(zlib.decompress(base64.b64decode(pv)))
os.system('chown nginx:nginx /var/www/luxe-talent/dist/performer-view.html')
print('performer-view.html deployed')

# 2. Add plain_password column
sql = "ALTER TABLE registration ADD COLUMN plain_password VARCHAR(255) DEFAULT '' AFTER password_hash"
r = subprocess.run(['mysql','-u','root','-pSonia@7700','luxe_talent','-e',sql], capture_output=True, text=True)
if r.returncode == 0:
    print('plain_password column added')
else:
    if 'Duplicate' in r.stderr:
        print('plain_password column already exists')
    else:
        print('DB error: ' + r.stderr)

# 3. Set existing performers to default password
sql2 = "UPDATE registration SET plain_password='Temp4Pass' WHERE plain_password='' OR plain_password IS NULL"
subprocess.run(['mysql','-u','root','-pSonia@7700','luxe_talent','-e',sql2], capture_output=True, text=True)
print('Existing performers set to Temp4Pass')

# 4. Patch register.php to store plain password
rp = '/var/www/luxe-talent/dist/api/register.php'
t = open(rp).read()
if 'plain_password' not in t:
    # Find where password_hash is inserted and add plain_password
    t = t.replace('password_hash(, PASSWORD_BCRYPT)', 'password_hash(, PASSWORD_BCRYPT)')
    # Add plain_password storage after the INSERT
    if '=(' in t or "\=\(" in t:
        t = t.replace("\=\('password')", "\=\('password');\=")
    # Find INSERT and add plain_password column
    old_ins = 'password_hash,'
    if old_ins in t and 'plain_password,' not in t:
        t = t.replace('password_hash,', 'password_hash,plain_password,')
        t = t.replace('password_hash(, PASSWORD_BCRYPT),', 'password_hash(, PASSWORD_BCRYPT),,')
        open(rp,'w').write(t)
        print('register.php patched to store plain password')
    else:
        print('register.php: could not find INSERT pattern')
else:
    print('register.php already stores plain_password')

# 5. Patch performers.php to include plain_password
pp = '/var/www/luxe-talent/dist/api/performers.php'
t2 = open(pp).read()
if "unset(\['password_hash'])" in t2:
    t2 = t2.replace("unset(\['password_hash']);", "\['plain_password'] = \['plain_password'] ?? '';
unset(\['password_hash']);")
    open(pp,'w').write(t2)
    print('performers.php patched to return plain_password')
elif 'plain_password' in t2:
    print('performers.php already returns plain_password')
else:
    print('performers.php: pattern not found')

# 6. Grant ALTER to app user for future
subprocess.run(['mysql','-u','root','-pSonia@7700','luxe_talent','-e',
    "GRANT SELECT,INSERT,UPDATE,DELETE ON luxe_talent.* TO 'Ezmator7700'@'localhost'; FLUSH PRIVILEGES;"],
    capture_output=True, text=True)

print('Done!')
