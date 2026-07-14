import {readFileSync, writeFileSync} from 'node:fs';

for (const file of process.argv.slice(2)) {
  const content = readFileSync(file, 'utf8');
  writeFileSync(file, content.replace(/[ \t]+$/gm, ''), 'utf8');
}
