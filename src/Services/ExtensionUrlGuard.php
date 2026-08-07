<?php
namespace Azuriom\Plugin\GamingHubManager\Services;
use Azuriom\Plugin\GamingHubManager\Exceptions\UnsafeExtensionUrl;
final class ExtensionUrlGuard {
 public function assertSafe(string $url,bool $allowPrivate=false):void { $p=parse_url($url); if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])) throw new UnsafeExtensionUrl('Only credential-free HTTPS URLs are allowed.'); $host=strtolower($p['host']); if($host==='localhost'||str_ends_with($host,'.localhost')) throw new UnsafeExtensionUrl('Localhost is not allowed.'); $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:(gethostbynamel($host)?:[]); if($ips===[]) throw new UnsafeExtensionUrl('Host could not be resolved.'); foreach($ips as $ip){ if(!$allowPrivate && !$this->isPublicIp($ip)) throw new UnsafeExtensionUrl('Private, loopback, reserved, or link-local hosts are not allowed.'); } }
 private function isPublicIp(string $ip):bool{return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;}
 public function assertGithubRepository(string $url,bool $allowPrivate=false):array{$this->assertSafe($url,$allowPrivate);$p=parse_url($url);if(strtolower($p['host'])!=='github.com')throw new UnsafeExtensionUrl('Direct repositories must use github.com.');$parts=array_values(array_filter(explode('/',trim($p['path']??'','/'))));if(count($parts)!==2)throw new UnsafeExtensionUrl('Expected a public GitHub owner/repository URL.');return [$parts[0],preg_replace('/\.git$/','',$parts[1])];}
}
