const fs = require('fs');
const path = require('path');

async function generateUsersAndTokens() {
  const users = [];
  const tokens = [];
  const totalUsersNeeded = 250;
  
  const usersFile = path.join(__dirname, 'users.json');
  const tokensFile = path.join(__dirname, 'tokens.json');

  console.log(`[1/3] Generating ${totalUsersNeeded} local user records...`);
  
  // 1. Build the users array matching the structure k6 and your backend expects
  for (let i = 1; i <= totalUsersNeeded; i++) {
    users.push({
      email: `user_${i}@example.com`,
      password: 'YourSecurePassword123' // Make sure this matches your DB seeding password
    });
  }

  // 2. Save users.json so k6 can read it later
  fs.writeFileSync(usersFile, JSON.stringify(users, null, 2));
  console.log(`[2/3] Saved credential profiles to: ${usersFile}\n`);

  // 3. Authenticate against the backend to build tokens.json
  console.log(`[3/3] Authenticating users via backend to fetch tokens...\n`);

  for (let i = 0; i < users.length; i++) {
    const user = users[i];
    const displayIndex = i + 1;
    
    try {
      const response = await fetch('http://127.0.1/auth/login', {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          email: user.email,
          password: user.password
        })
      });

      if (!response.ok) {
        const errorText = await response.text();
        console.error(`[${displayIndex}/${totalUsersNeeded}] HTTP ${response.status} Error:`, errorText.slice(0, 100));
        continue;
      }

      const data = await response.json();
      const token = data.token || data.access_token || data.data?.token || data.authorisation?.token;

      if (token) {
        tokens.push(token);
        console.log(`[${displayIndex}/${totalUsersNeeded}] Success: ${user.email}`);
      } else {
        console.warn(`[${displayIndex}/${totalUsersNeeded}] Success, but no token field matched response keys:`, data);
      }
    } catch (error) {
      console.error(`[${displayIndex}/${totalUsersNeeded}] Connection error for ${user.email}:`, error.message);
    }
  }

  // 4. Save tokens.json for k6 environment usage
  fs.writeFileSync(tokensFile, JSON.stringify(tokens, null, 2));
  console.log(`\nCompleted! Saved ${tokens.length} tokens to: ${tokensFile}`);
}

generateUsersAndTokens();
