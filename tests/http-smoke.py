import urllib.request,urllib.error,json,base64
from pathlib import Path
import os
import hashlib
import re
from urllib.parse import urlparse
endpoint=os.environ['SITE_AGENT_HTTP_URL']
if urlparse(endpoint).hostname not in ('localhost','127.0.0.1'):
 raise RuntimeError('HTTP smoke tests require localhost.')
wordpress=os.environ.get('SITE_AGENT_WP_DIR')
if not wordpress:
 raise RuntimeError('Set SITE_AGENT_WP_DIR to a disposable WordPress installation marked with .site-agent-test-install.')
wordpress=Path(wordpress).resolve()
marker=wordpress/'.site-agent-test-install'
if not wordpress.is_dir() or not marker.is_file():
 raise RuntimeError('HTTP smoke tests require a local disposable WordPress installation marked with .site-agent-test-install.')
marker_sha256=hashlib.sha256(marker.read_bytes()).hexdigest()
credentials=json.loads(Path(os.environ['SITE_AGENT_TEST_CREDENTIALS']).read_text())
sequence=0
session=None
def rpc(method,params,role='admin'):
 global sequence,session
 sequence+=1
 headers={'Content-Type':'application/json','Accept':'application/json, text/event-stream'}
 if role:
  c=credentials[role];headers['Authorization']='Basic '+base64.b64encode((c['username']+':'+c['password']).encode()).decode()
 if session:headers['Mcp-Session-Id']=session;headers['MCP-Protocol-Version']='2025-11-25'
 request=urllib.request.Request(endpoint,json.dumps({'jsonrpc':'2.0','id':sequence,'method':method,'params':params}).encode(),headers)
 try:
  with urllib.request.urlopen(request,timeout=30) as response:
   body=json.loads(response.read());session=response.headers.get('Mcp-Session-Id',session);return response.status,body
 except urllib.error.HTTPError as response:
  global last_headers
  with response:
   last_headers=response.headers;return response.code,json.loads(response.read())
last_headers=None
code,body=rpc('initialize',{'protocolVersion':'2025-11-25','capabilities':{},'clientInfo':{'name':'qa','version':'1'}})
assert code==200 and session
code,body=rpc('tools/list',{})
tools=body['result']['tools'];names={t['name'] for t in tools}
# Every Site Agent tool with all groups enabled; Bricks tools appear only on a site running Bricks 2.4+.
CORE=['site-context','list-content','get-content','list-terms','list-media','list-skills','get-skill','save-content','upload-media','update-media','list-files','read-file','write-file','create-directory','delete-file','move-file','execute-php','run-wp-cli']
missing=[n for n in CORE if 'site-agent-'+n not in names];assert not missing,missing
bricks=sorted(n for n in names if n.startswith('bricks-'))
assert {n for n in names if n.startswith('site-agent-')}-{'site-agent-'+n for n in CORE}<={'site-agent-bricks-abilities','site-agent-run-bricks-ability'},names
assert all(t['inputSchema'].get('type')=='object' for t in tools)
print('PASS initialize and list all',len(CORE),'Site Agent tools','and %d Bricks tools'%len(bricks) if bricks else '')
def tool(name,args):
 code,body=rpc('tools/call',{'name':'site-agent-'+name,'arguments':args})
 assert code==200,(code,body)
 return body['result']
# This fixed, read-only probe must succeed before any mutation or arbitrary PHP.
# A localhost URL may point at a tunnel to a different, non-disposable installation.
body=tool('execute-php',{'code':'''$marker = ABSPATH . '.site-agent-test-install';
return array(
    'wordpress_directory' => realpath(ABSPATH),
    'test_install' => is_file($marker),
    'marker_sha256' => is_file($marker) ? hash_file('sha256', $marker) : ''
);'''})
if body.get('isError'):
 raise RuntimeError('Could not verify the disposable HTTP target.')
proof=body.get('structuredContent',{}).get('return_value',{})
if proof != {'wordpress_directory':str(wordpress),'test_install':True,'marker_sha256':marker_sha256}:
 raise RuntimeError('HTTP target is not the marked local disposable WordPress installation.')
print('PASS HTTP target matches the marked disposable WordPress fixture')
body=tool('execute-php',{'code':r'''return array(
    'process_available' => \SiteAgent\Process::available(),
    'cli_available' => '' !== \SiteAgent\Developer::cli_binary(),
    'unavailable_message' => __('WP-CLI and process execution must be available on the server. Set SITE_AGENT_WP_CLI to the wp executable if it is installed elsewhere.', 'site-agent')
);'''})
assert not body.get('isError'),body
cli_runtime=body.get('structuredContent',{}).get('return_value',{})
assert type(cli_runtime.get('process_available')) is bool and type(cli_runtime.get('cli_available')) is bool,body
assert isinstance(cli_runtime.get('unavailable_message'),str) and cli_runtime['unavailable_message'],body
cli_available=cli_runtime['process_available'] and cli_runtime['cli_available']
cli_missing=', '.join(label for key,label in [('process_available','process execution'),('cli_available','WP-CLI binary')] if not cli_runtime[key])
body=tool('execute-php',{'code':'echo "http-output"; return array("wp" => get_bloginfo("version"));'})
print('PASS authenticated PHP execution')
assert not body.get('isError')
body=tool('site-context',{})
assert not body.get('isError');print('PASS authenticated WordPress context')
body=tool('execute-php',{'code':'return 1;','post_id':1})
assert body.get('isError');print('PASS strict MCP tool schemas')
body=tool('run-wp-cli',{'arguments':['core','version']})
if cli_available:
 assert not body.get('isError'),body
 structured=body.get('structuredContent',{})
 assert structured.get('exit_code')==0,body
 assert structured.get('stdout','').strip(),body
 print('PASS bounded WP-CLI command as authenticated administrator')
else:
 # The MCP adapter exposes the WP_Error message rather than its cli_unavailable code.
 errors=[part.get('text') for part in body.get('content',[]) if part.get('type')=='text']
 assert body.get('isError') is True and errors==[cli_runtime['unavailable_message']],body
 print('PASS WP-CLI returns its documented cli_unavailable error')
 print('SKIP native WP-CLI execution: server lacks '+cli_missing)
code,body=rpc('tools/list',{},role='contributor');assert code==403
code,body=rpc('tools/list',{},role=None);assert code==401
assert last_headers.get('X-Site-Agent-Auth')=='unauthenticated',dict(last_headers)
print('PASS session does not grant access to contributor or anonymous callers')
BUILTIN_SKILLS=['gutenberg','generateblocks','elementor','bricks','divi','mailerhive','core-support','fluentcart','gt-extensions-fluentcart','stackless','page-blocks-builder']
body=tool('list-skills',{})
assert not body.get('isError'),body
skills=body['structuredContent']['skills']
skill_names=[skill['name'] for skill in skills]
assert len(skill_names)==len(set(skill_names)),body
missing=set(BUILTIN_SKILLS)-set(skill_names)
assert not missing,('Missing built-in skills',sorted(missing))
catalog={skill['name']:skill for skill in skills}
body=tool('get-skill',{'skill':'bricks','path':'../gutenberg/SKILL.md'})
assert body.get('isError'),body
for skill in BUILTIN_SKILLS:
 assert catalog[skill]['source']=='site-agent' and catalog[skill]['description'] and 'SKILL.md' in catalog[skill]['files'],catalog[skill]
 body=tool('get-skill',{'skill':skill})
 assert not body.get('isError'),body
 guide=body['structuredContent']
 assert guide['skill']==skill and guide['path']=='SKILL.md' and 'SKILL.md' in guide['files'],body
 content=guide['content']
 assert content.startswith('---\n') and '\n---\n' in content and re.search(r'^name: '+re.escape(skill)+r'$',content,re.M),body
print('PASS all',len(BUILTIN_SKILLS),'built-in skills over MCP, with',len(skills)-len(BUILTIN_SKILLS),'plugin-registered skills')
body=tool('create-directory',{'path':'mu-plugins'})
assert not body.get('isError'),body
body=tool('write-file',{'path':'mu-plugins/site-agent-smoke.php','content':'<?php site_agent_smoke_undefined_function();','expected_sha256':'new'})
assert body.get('isError') and 'reverted' in body['content'][0]['text'],body
body=tool('write-file',{'path':'mu-plugins/site-agent-smoke.php','content':'<?php // Site Agent smoke test.','expected_sha256':'new'})
assert not body.get('isError') and body['structuredContent']['checks']['health']=='ok',body
body=tool('delete-file',{'path':'mu-plugins/site-agent-smoke.php','expected_sha256':body['structuredContent']['sha256']})
assert not body.get('isError'),body
print('PASS fatal PHP changes are detected through the site and reverted')
if bricks:
 body=tool('bricks-abilities',{});assert not body.get('isError'),body
 listed=body['structuredContent']['abilities'];direct={a['direct_tool'] for a in listed if a['direct_tool']}
 assert set(bricks)==direct,(bricks,direct)
 body=tool('run-bricks-ability',{'ability_name':'bricks/get-mcp-version'});assert not body.get('isError'),body
 code,body=rpc('tools/call',{'name':'bricks-start-here','arguments':{}});assert code==200 and not body['result'].get('isError'),body
 body=tool('run-bricks-ability',{'ability_name':'bricks/not-a-real-ability'});assert body.get('isError'),body
 print('PASS Bricks abilities: %d listed, %d direct'%(len(listed),len(bricks)))
if cli_available:
 print('All HTTP MCP smoke checks passed.')
else:
 print('HTTP MCP smoke checks passed with native WP-CLI execution skipped: server lacks '+cli_missing+'.')
