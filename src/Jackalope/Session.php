<?php

namespace Jackalope;

use ArrayIterator;
use PHPCR\PropertyType;

/**
 * The Session object provides read and (if implemented) write access to the
 * content of a particular workspace in the repository.
 *
 * The Session object is returned by Repository.login(). It encapsulates both
 * the authorization settings of a particular user (as specified by the passed
 * Credentials) and a binding to the workspace specified by the workspaceName
 * passed on login.
 *
 * Each Session object is associated one-to-one with a Workspace object. The
 * Workspace object represents a "view" of an actual repository workspace
 * entity as seen through the authorization settings of its associated Session.
 */
class Session implements \PHPCR\SessionInterface
{
    /**
     * A registry for all created sessions to be able to reference them by id in
     * the stream wrapper for lazy loading binary properties.
     *
     * Keys are spl_object_hash'es for the sessions which are the values
     */
    protected static $sessionRegistry = array();

    /**
     * The factory to instantiate objects
     * @var Factory
     */
    protected $factory;

    /**
     * @var Repository
     */
    protected $repository;
    /**
     * @var Workspace
     */
    protected $workspace;
    /**
     * @var ObjectManager
     */
    protected $objectManager;
    /**
     * @var \PHPCR\Transaction\UserTransactionInterface
     */
    protected $utx = null;
    /**
     * @var \PHPCR\SimpleCredentials
     */
    protected $credentials;
    /**
     * @var bool
     */
    protected $logout = false;
    /**
     * The namespace registry.
     *
     * It is only used to check prefixes and at setup.
     * Session remapping must be handled locally.
     *
     * @var NamespaceRegistry
     */
    protected $namespaceRegistry;

    /**
     * List of local namespaces
     *
     * TODO: implement local namespace rewriting
     * see jackrabbit-spi-commons/src/main/java/org/apache/jackrabbit/spi/commons/conversion/PathParser.java and friends
     * for how this is done in jackrabbit
     */
    //protected $localNamespaces;

    /** creates the corresponding workspace */
    public function __construct($factory, Repository $repository, $workspaceName, \PHPCR\SimpleCredentials $credentials, TransportInterface $transport)
    {
        $this->factory = $factory;
        $this->repository = $repository;
        $this->objectManager = $this->factory->get('ObjectManager', array($transport, $this));
        $this->workspace = $this->factory->get('Workspace', array($this, $this->objectManager, $workspaceName));
        $this->utx = $this->workspace->getTransactionManager();
        $this->credentials = $credentials;
        $this->namespaceRegistry = $this->workspace->getNamespaceRegistry();
        self::registerSession($this);

        $transport->setNodeTypeManager($this->workspace->getNodeTypeManager());
    }

    /**
     * Returns the Repository object through which this session was acquired.
     *
     * @return \PHPCR\RepositoryInterface a Repository object.
     * @api
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Gets the user ID associated with this Session. How the user ID is set is
     * up to the implementation, it may be a string passed in as part of the
     * credentials or it may be a string acquired in some other way. This method
     * is free to return an "anonymous user ID" or null.
     *
     * @return string The user id associated with this Session.
     * @api
     */
    public function getUserID()
    {
        return $this->credentials->getUserID(); //TODO: what if its not simple credentials? what about anonymous login?
    }

    /**
     * Returns the names of the attributes set in this session as a result of
     * the Credentials that were used to acquire it. Not all Credentials
     * implementations will contain attributes (though, for example,
     * SimpleCredentials does allow for them). This method returns an empty
     * array if the Credentials instance did not provide attributes.
     *
     * @return array A string array containing the names of all attributes passed in the credentials used to acquire this session.
     * @api
     */
    public function getAttributeNames()
    {
        return $this->credentials->getAttributeNames();
    }

    /**
     * Returns the value of the named attribute as an Object, or null if no
     * attribute of the given name exists. See getAttributeNames().
     *
     * @param string $name The name of an attribute passed in the credentials used to acquire this session.
     * @return object The value of the attribute or null if no attribute of the given name exists.
     * @api
     */
    public function getAttribute($name)
    {
        return $this->credentials->getAttribute($name);
    }

    /**
     * Returns the Workspace attached to this Session.
     *
     * @return \PHPCR\WorkspaceInterface a Workspace object.
     * @api
     */
    public function getWorkspace()
    {
        return $this->workspace;
    }

    /**
     * Returns the root node of the workspace, "/". This node is the main access
     * point to the content of the workspace.
     *
     * @return \PHPCR\NodeInterface The root node of the workspace: a Node object.
     * @throws RepositoryException if an error occurs.
     * @api
     */
    public function getRootNode()
    {
        return $this->getNode('/');
    }

    /**
     * Returns a new session in accordance with the specified (new) Credentials.
     * Allows the current user to "impersonate" another using incomplete or relaxed
     * credentials requirements (perhaps including a user name but no password, for
     * example), assuming that this Session gives them that permission.
     * The new Session is tied to a new Workspace instance. In other words, Workspace
     * instances are not re-used. However, the Workspace instance returned represents
     * the same actual persistent workspace entity in the repository as is represented
     * by the Workspace object tied to this Session.
     *
     * @param \PHPCR\CredentialsInterface $credentials A Credentials object
     * @return \PHPCR\SessionInterface a Session object
     * @throws \PHPCR\LoginException if the current session does not have sufficient access to perform the operation.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function impersonate(\PHPCR\CredentialsInterface $credentials)
    {
        throw new \PHPCR\LoginException('Not supported');
    }

    /**
     * Returns the node specified by the given identifier.
     *
     * Applies to both referenceable and non-referenceable nodes.
     *
     * @param string $id An identifier.
     * @return \PHPCR\NodeInterface A Node.
     *
     * @throws \PHPCR\ItemNotFoundException if no node with the specified identifier exists or if this Session does not have read access to the node with the specified identifier.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getNodeByIdentifier($id)
    {
        return $this->objectManager->getNode($id);
    }

    /**
     * Returns the node specified by the given identifier.
     *
     * Applies to both referenceable and non-referenceable nodes.
     *
     * Note uuid's that cannot be found will be ignored
     *
     * @param string $ids An array of identifier.
     * @return array containing \PHPCR\NodeInterface nodes keyed by uuid
     *
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getNodesByIdentifier($ids)
    {
        $nodesByPath = $this->objectManager->getNodes($ids);
        $nodesByUUID = array();
        foreach ($nodesByPath as $node) {
            $nodesByUUID[$node->getIdentifier()] = $node;
        }
        return new ArrayIterator($nodesByUUID);
    }

    /**
     * Returns the node at the specified absolute path in the workspace. If no such
     * node exists, then it returns the property at the specified path.
     *
     * This method should only be used if the application does not know whether the
     * item at the indicated path is property or node. In cases where the application
     * has this information, either getNode(java.lang.String) or
     * getProperty(java.lang.String) should be used, as appropriate. In many repository
     * implementations the node and property-specific methods are likely to be more
     * efficient than getItem.
     *
     * @param string $absPath An absolute path.
     * @return \PHPCR\ItemInterface
     * @throws \PHPCR\PathNotFoundException if no accessible item is found at the specified path.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getItem($absPath)
    {
        if (strpos($absPath,'/') !== 0) {
            throw new \PHPCR\PathNotFoundException('It is forbidden to call getItem on session with a relative path');
        }

        if ($this->nodeExists($absPath)) {
            return $this->getNode($absPath);
        }
        return $this->getProperty($absPath);
    }

    /**
     * Returns the node at the specified absolute path in the workspace.
     *
     * @param string $absPath An absolute path.
     * @return \PHPCR\NodeInterface A node
     *
     * @throws \PHPCR\PathNotFoundException if no accessible node is found at the specified path.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getNode($absPath)
    {
        try {
            return $this->objectManager->getNodeByPath($absPath);
        } catch (\PHPCR\ItemNotFoundException $e) {
            throw new \PHPCR\PathNotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Returns all nodes specified in the absPath array.
     *
     * Note path's that cannot be found will be ignored
     *
     * @param array $absPaths An array containing absolute paths.
     * @return array containing \PHPCR\NodeInterface nodes keyed by path
     *
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getNodes($absPaths)
    {
        return $this->objectManager->getNodesByPath($absPaths);
    }

    /**
     * Returns the property at the specified absolute path in the workspace.
     *
     * @param string $absPath An absolute path.
     * @return \PHPCR\PropertyInterface A property
     * @throws \PHPCR\PathNotFoundException if no accessible property is found at the specified path.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getProperty($absPath)
    {
        try {
            return $this->objectManager->getPropertyByPath($absPath);
        } catch (\PHPCR\ItemNotFoundException $e) {
            throw new \PHPCR\PathNotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Returns true if an item exists at absPath and this Session has read
     * access to it; otherwise returns false.
     *
     * @param string $absPath An absolute path.
     * @return boolean a boolean
     * @throws \PHPCR\RepositoryException if absPath is not a well-formed absolute path.
     * @api
     */
    public function itemExists($absPath)
    {
        if ($absPath == '/') {
            return true;
        }
        return $this->nodeExists($absPath) || $this->propertyExists($absPath);
    }

    /**
     * Returns true if a node exists at absPath and this Session has read
     * access to it; otherwise returns false.
     *
     * @param string $absPath An absolute path.
     * @return boolean a boolean
     * @throws \PHPCR\RepositoryException if absPath is not a well-formed absolute path.
     * @api
     */
    public function nodeExists($absPath)
    {
        if ($absPath == '/') {
            return true;
        }

        try {
            //OPTIMIZE: avoid throwing and catching errors would improve performance if many node exists calls are made
            //would need to communicate to the lower layer that we do not want exceptions
            $this->getNode($absPath);
        } catch(\PHPCR\PathNotFoundException $e) {
            return false;
        }
        return true;
    }

    /**
     * Returns true if a property exists at absPath and this Session has read
     * access to it; otherwise returns false.
     *
     * @param string $absPath An absolute path.
     * @return boolean a boolean
     * @throws \PHPCR\RepositoryException if absPath is not a well-formed absolute path.
     * @api
     */
    public function propertyExists($absPath)
    {
        try {
            //OPTIMIZE: avoid throwing and catching errors would improve performance if many node exists calls are made
            //would need to communicate to the lower layer that we do not want exceptions
            $this->getProperty($absPath);
        } catch(\PHPCR\PathNotFoundException $e) {
            return false;
        }
        return true;

    }

    /**
     * Moves the node at srcAbsPath (and its entire subgraph) to the new location
     * at destAbsPath.
     *
     * This is a session-write method and therefore requires a save to dispatch
     * the change.
     *
     * The identifiers of referenceable nodes must not be changed by a move. The
     * identifiers of non-referenceable nodes may change.
     *
     * A ConstraintViolationException is thrown on persist
     * if performing this operation would violate a node type or
     * implementation-specific constraint.
     *
     * As well, a ConstraintViolationException will be thrown on persist if an
     * attempt is made to separately save either the source or destination node.
     *
     * Note that this behaviour differs from that of Workspace.move($srcAbsPath,
     * $destAbsPath), which is a workspace-write method and therefore
     * immediately dispatches changes.
     *
     * The destAbsPath provided must not have an index on its final element. If
     * ordering is supported by the node type of the parent node of the new location,
     * then the newly moved node is appended to the end of the child node list.
     *
     * This method cannot be used to move an individual property by itself. It
     * moves an entire node and its subgraph.
     *
     * @param string $srcAbsPath the root of the subgraph to be moved.
     * @param string $destAbsPath the location to which the subgraph is to be moved.
     * @return void
     * @throws \PHPCR\ItemExistsException if a node already exists at destAbsPath and same-name siblings are not allowed.
     * @throws \PHPCR\PathNotFoundException if either srcAbsPath or destAbsPath cannot be found and this implementation performs this validation immediately.
     * @throws \PHPCR\Version\VersionException if the parent node of destAbsPath or the parent node of srcAbsPath is versionable and checked-in, or or is non-versionable and its nearest versionable ancestor is checked-in and this implementation performs this validation immediately.
     * @throws \PHPCR\ConstraintViolationException if a node-type or other constraint violation is detected immediately and this implementation performs this validation immediately.
     * @throws \PHPCR\Lock\LockException if the move operation would violate a lock and this implementation performs this validation immediately.
     * @throws \PHPCR\RepositoryException if the last element of destAbsPath has an index or if another error occurs.
     * @api
     */
    public function move($srcAbsPath, $destAbsPath)
    {
        if ($this->itemExists($destAbsPath)) {
            // TODO same-name siblings
            throw new \PHPCR\ItemExistsException('Target item already exists at '.$destAbsPath);
        }
        $this->objectManager->moveNode($srcAbsPath, $destAbsPath);
    }

    /**
     * Removes the specified item and its subgraph.
     *
     * This is a session-write method and therefore requires a save in order to
     * dispatch the change.
     *
     * If a node with same-name siblings is removed, this decrements by one the
     * indices of all the siblings with indices greater than that of the removed
     * node. In other words, a removal compacts the array of same-name siblings
     * and causes the minimal re-numbering required to maintain the original
     * order but leave no gaps in the numbering.
     *
     * @param string $absPath the absolute path of the item to be removed.
     * @return void
     * @throws \PHPCR\Version\VersionException if the parent node of the item at absPath is read-only due to a checked-in node and this implementation performs this validation immediately.
     * @throws \PHPCR\Lock\LockException if a lock prevents the removal of the specified item and this implementation performs this validation immediately instead.
     * @throws \PHPCR\ConstraintViolationException if removing the specified item would violate a node type or implementation-specific constraint and this implementation performs this validation immediately.
     * @throws \PHPCR\PathNotFoundException if no accessible item is found at $absPath property or if the specified item or an item in its subgraph is currently the target of a REFERENCE property located in this workspace but outside the specified item's subgraph and the current Session does not have read access to that REFERENCE property.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @see \PHPCR\ItemInterface::remove()
     * @api
     */
    public function removeItem($absPath)
    {
        $item = $this->getItem($absPath);
        $item->remove();
    }

    /**
     * Validates all pending changes currently recorded in this Session. If
     * validation of all pending changes succeeds, then this change information
     * is cleared from the Session.
     *
     * If the save occurs outside a transaction, the changes are dispatched and
     * persisted. Upon being persisted the changes become potentially visible to
     * other Sessions bound to the same persitent workspace.
     *
     * If the save occurs within a transaction, the changes are dispatched but
     * are not persisted until the transaction is committed.
     *
     * If validation fails, then no pending changes are dispatched and they
     * remain recorded on the Session. There is no best-effort or partial save.
     *
     * @return void
     * @throws \PHPCR\AccessDeniedException if any of the changes to be persisted would violate the access privileges of the this Session. Also thrown if any of the changes to be persisted would cause the removal of a node that is currently referenced by a REFERENCE property that this Session does not have read access to.
     * @throws \PHPCR\ItemExistsException if any of the changes to be persisted would be prevented by the presence of an already existing item in the workspace.
     * @throws \PHPCR\ConstraintViolationException if any of the changes to be persisted would violate a node type or restriction. Additionally, a repository may use this exception to enforce implementation- or configuration-dependent restrictions.
     * @throws \PHPCR\InvalidItemStateException if any of the changes to be persisted conflicts with a change already persisted through another session and the implementation is such that this conflict can only be detected at save-time and therefore was not detected earlier, at change-time.
     * @throws \PHPCR\ReferentialIntegrityException if any of the changes to be persisted would cause the removal of a node that is currently referenced by a REFERENCE property that this Session has read access to.
     * @throws \PHPCR\Version\VersionException if the save would make a result in a change to persistent storage that would violate the read-only status of a checked-in node.
     * @throws \PHPCR\Lock\LockException if the save would result in a change to persistent storage that would violate a lock.
     * @throws \PHPCR\NodeType\NoSuchNodeTypeException if the save would result in the addition of a node with an unrecognized node type.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function save()
    {
        if ($this->utx && !$this->utx->inTransaction()) {
            // do the operation in a short transaction
            $this->utx->begin();
            try {
                $this->objectManager->save();
                $this->utx->commit();
            } catch(\Exception $e) {
                // if anything goes wrong, rollback this mess
                $this->utx->rollback();
                // but do not eat the exception
                throw $e;
            }
        } else {
            $this->objectManager->save();
        }
    }

    /**
     * If keepChanges is false, this method discards all pending changes currently
     * recorded in this Session and returns all items to reflect the current saved
     * state. Outside a transaction this state is simply the current state of
     * persistent storage. Within a transaction, this state will reflect persistent
     * storage as modified by changes that have been saved but not yet committed.
     * If keepChanges is true then pending change are not discarded but items that
     * do not have changes pending have their state refreshed to reflect the current
     * saved state, thus revealing changes made by other sessions.
     *
     * @param boolean $keepChanges a boolean
     * @return void
     * @throws \PHPCR\RepositoryException if an error occurs.
     * @api
     */
    public function refresh($keepChanges)
    {
        throw new NotImplementedException('Write');

        //TODO: is clearing out object manager cache enough?
        //the $keepChanges option seems not important in php context. we have no long running sessions with the server and don't need to sync changes from server.
    }

    /**
     * Clears the state of the current session
     *
     * Removes all cached objects, planned changes etc. Mostly useful for testing purposes.
     */
    public function clear()
    {
        $this->objectManager->clear();
    }

    /**
     * Returns true if this session holds pending (that is, unsaved) changes;
     * otherwise returns false.
     *
     * @return boolean a boolean
     * @throws \PHPCR\RepositoryException if an error occurs
     * @api
     */
    public function hasPendingChanges()
    {
        return $this->objectManager->hasPendingChanges();
    }

    /**
     * Returns true if this Session has permission to perform the specified
     * actions at the specified absPath and false otherwise.
     *
     * The actions parameter is a comma separated list of action strings. The
     * following action strings are defined:
     *
     *   - add_node: If hasPermission(path, "add_node") returns true, then this
     *     Session has permission to add a node at path.
     *
     *   - set_property: If hasPermission(path, "set_property") returns true,
     *     then this Session has permission to set (add or change) a property at
     *     path.
     *
     *   - remove: If hasPermission(path, "remove") returns true, then this
     *     Session has permission to remove an item at path.
     *
     *   - read: If hasPermission(path, "read") returns true, then this Session
     *     has permission to retrieve (and read the value of, in the case of a
     *     property) an item at path.
     *
     * When more than one action is specified in the actions parameter, this
     * method will only return true if this Session has permission to perform
     * all of the listed actions at the specified path.
     *
     * The information returned through this method will only reflect the access
     * control status (both JCR defined and implementation-specific) and not
     * other restrictions that may exist, such as node type constraints. For
     * example, even though hasPermission may indicate that a particular Session
     * may add a property at /A/B/C, the node type of the node at /A/B may
     * prevent the addition of a property called C.
     *
     * @param string $absPath an absolute path.
     * @param string $actions a comma separated list of action strings.
     * @return boolean true if this Session has permission to perform the specified actions at the specified absPath.
     * @throws \PHPCR\RepositoryException if an error occurs.
     * @api
     */
    public function hasPermission($absPath, $actions)
    {
        $actualPermissions = $this->objectManager->getPermissions($absPath);
        $requestedPermissions = explode(',', $actions);

        foreach ($requestedPermissions as $perm) {
            if (! in_array(strtolower(trim($perm)), $actualPermissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * If hasPermission returns false, throws the security exception
     *
     * @param string $absPath an absolute path.
     * @param string $actions a comma separated list of action strings.
     * @return void
     * @throws \PHPCR\Security\AccessControlException If permission is denied.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function checkPermission($absPath, $actions)
    {
        if (! $this->hasPermission($absPath, $actions)) {
            throw new \PHPCR\Security\AccessControlException($absPath);
        }
    }

    /**
     * not really anything right now
     *
     * @param string $methodName the name of the method.
     * @param object $target the target object of the operation.
     * @param array $arguments the arguments of the operation.
     * @return boolean false if the operation cannot be performed, true if the operation can be performed or if the repository cannot determine whether the operation can be performed.
     * @throws \PHPCR\RepositoryException if an error occurs
     * @api
     */
    public function hasCapability($methodName, $target, array $arguments)
    {
        //we never determine wether operation can be performed as it is optional ;-)
        //TODO: could implement some
        return true;
    }

    /**
     * not implemented
     */
    public function importXML($parentAbsPath, $in, $uuidBehavior)
    {
        throw new NotImplementedException('Write');
    }

    /**
     * Serializes the node (and if $noRecurse is false, the whole subgraph) at
     * $absPath as an XML stream and outputs it to the supplied URI. The
     * resulting XML is in the system view form. Note that $absPath must be
     * the path of a node, not a property.
     *
     * If $skipBinary is true then any properties of PropertyType.BINARY will be serialized
     * as if they are empty. That is, the existence of the property will be serialized,
     * but its content will not appear in the serialized output (the <sv:value> element
     * will have no content). Note that in the case of multi-value BINARY properties,
     * the number of values in the property will be reflected in the serialized output,
     * though they will all be empty. If $skipBinary is false then the actual value(s)
     * of each BINARY property is recorded using Base64 encoding.
     *
     * If $noRecurse is true then only the node at $absPath and its properties, but not
     * its child nodes, are serialized. If $noRecurse is false then the entire subgraph
     * rooted at $absPath is serialized.
     *
     * If the user lacks read access to some subsection of the specified tree, that
     * section simply does not get serialized, since, from the user's point of view,
     * it is not there.
     *
     * The serialized output will reflect the state of the current workspace as
     * modified by the state of this Session. This means that pending changes
     * (regardless of whether they are valid according to node type constraints)
     * and all namespace mappings in the namespace registry, as modified by the
     * current session-mappings, are reflected in the output.
     *
     * The output XML will be encoded in UTF-8.
     *
     * @param string $absPath The path of the root of the subgraph to be serialized. This must be the path to a node, not a property
     * @param string $out The URI to which the XML serialization of the subgraph will be output.
     * @param boolean $skipBinary A boolean governing whether binary properties are to be serialized.
     * @param boolean $noRecurse A boolean governing whether the subgraph at absPath is to be recursed.
     * @return void
     * @throws \PHPCR\PathNotFoundException if no node exists at absPath.
     * @throws RuntimeException if an error during an I/O operation occurs.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function exportSystemView($absPath, $stream, $skipBinary, $noRecurse)
    {
        $node = $this->getNode($absPath);

        fwrite($stream, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
        $this->exportSystemViewRecursive($node, $stream, $skipBinary, $noRecurse, true);
    }

    /**
     * Recursively output node and all its children into the file in the system view format
     *
     * @param NodeInterface $node the node to output
     * @param resource $stream The stream resource (i.e. aquired with fopen) to which the XML serialization of the subgraph will be output. Must support the fwrite method.
     * @param boolean $skipBinary A boolean governing whether binary properties are to be serialized.
     * @param boolean $noRecurse A boolean governing whether the subgraph at absPath is to be recursed.
     * @param boolean $root Whether this is the root node of the resulting document, meaning the namespace declarations have to be included in it
     *
     * @return void
     */
    private function exportSystemViewRecursive($node, $stream, $skipBinary, $noRecurse, $root=false)
    {
        fwrite($stream, '<sv:node');
        if ($root) {
            $this->exportNamespaceDeclarations($stream);
        }
        fwrite($stream, ' sv:name="'.($node->getPath() == '/' ? 'jcr:root' : htmlspecialchars($node->getName())).'">');

        // the order MUST be primary type, then mixins, if any, then jcr:uuid if its a referenceable node
        fwrite($stream, '<sv:property sv:name="jcr:primaryType" sv:type="Name"><sv:value>'.htmlspecialchars($node->getPropertyValue('jcr:primaryType')).'</sv:value></sv:property>');
        if ($node->hasProperty('jcr:mixinTypes')) {
            fwrite($stream, '<sv:property sv:name="jcr:mixinTypes" sv:type="Name">');
            foreach ($node->getPropertyValue('jcr:mixinTypes') as $type) {
                fwrite($stream, '<sv:value>'.htmlspecialchars($type).'</sv:value>');
            }
            fwrite($stream, '</sv:property>');
        }
        if ($node->isNodeType('mix:referenceable')) {
            fwrite($stream, '<sv:property sv:name="jcr:uuid" sv:type="String"><sv:value>'.$node->getIdentifier().'</sv:value></sv:property>');
        }

        foreach ($node->getProperties() as $name => $property) {
            if ($name == 'jcr:primaryType' || $name == 'jcr:mixinTypes' || $name == 'jcr:uuid') {
                // explicitly handled before
                continue;
            }
            if (PropertyType::BINARY == $property->getType() && $skipBinary) {
                // do not output binary data in the xml
                continue;
            }
            fwrite($stream, '<sv:property sv:name="'.htmlentities($name).'" sv:type="'
                                . PropertyType::nameFromValue($property->getType()).'"'
                                . ($property->isMultiple() ? ' sv:multiple="true"' : '')
                                . '>');
            $values = $property->isMultiple() ? $property->getString() : array($property->getString());

            foreach ($values as $value) {
                if (PropertyType::BINARY == $property->getType()) {
                    $val = base64_encode($value);
                } else {
                    $val = htmlspecialchars($value);
                    //TODO: can we still have invalid characters after this? if so base64 and property, xsi:type="xsd:base64Binary"
                }
                fwrite($stream, "<sv:value>$val</sv:value>");
            }
            fwrite($stream, "</sv:property>");
        }
        if (! $noRecurse) {
            foreach ($node as $child) {
                $this->exportSystemViewRecursive($child, $stream, $skipBinary, $noRecurse);
            }
        }
        fwrite($stream, '</sv:node>');
    }

    /**
     * Serializes the node (and if $noRecurse is false, the whole subgraph) at
     * $absPath as an XML stream and outputs it to the supplied URI. The
     * resulting XML is in the document view form. Note that $absPath must be
     * the path of a node, not a property.
     *
     * If $skipBinary is true then any properties of PropertyType.BINARY will be serialized as if
     * they are empty. That is, the existence of the property will be serialized, but its content
     * will not appear in the serialized output (the value of the attribute will be empty). If
     * $skipBinary is false then the actual value(s) of each BINARY property is recorded using
     * Base64 encoding.
     *
     * If $noRecurse is true then only the node at $absPath and its properties, but not its
     * child nodes, are serialized. If $noRecurse is false then the entire subgraph rooted at
     * $absPath is serialized.
     *
     * If the user lacks read access to some subsection of the specified tree, that section
     * simply does not get serialized, since, from the user's point of view, it is not there.
     *
     * The serialized output will reflect the state of the current workspace as modified by
     * the state of this Session. This means that pending changes (regardless of whether they
     * are valid according to node type constraints) and all namespace mappings in the
     * namespace registry, as modified by the current session-mappings, are reflected in
     * the output.
     *
     * The output XML will be encoded in UTF-8.
     *
     * @param string $absPath The path of the root of the subgraph to be serialized. This must be the path to a node, not a property
     * @param resource $stream The stream resource (i.e. aquired with fopen) to which the XML serialization of the subgraph will be output. Must support the fwrite method.
     * @param boolean $skipBinary A boolean governing whether binary properties are to be serialized.
     * @param boolean $noRecurse A boolean governing whether the subgraph at absPath is to be recursed.
     * @return void
     * @throws \PHPCR\PathNotFoundException if no node exists at absPath.
     * @throws RuntimeException if an error during an I/O operation occurs.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function exportDocumentView($absPath, $stream, $skipBinary, $noRecurse)
    {
        $node = $this->getNode($absPath);

        fwrite($stream, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
        $this->exportDocumentViewRecursive($node, $stream, $skipBinary, $noRecurse, true);
    }

    /**
     * Recursively output node and all its children into the file in the document view format
     *
     * @param NodeInterface $node the node to output
     * @param resource $stream the resource to write data out to
     * @param boolean $skipBinary A boolean governing whether binary properties are to be serialized.
     * @param boolean $noRecurse A boolean governing whether the subgraph at absPath is to be recursed.
     * @param boolean $root Whether this is the root node of the resulting document, meaning the namespace declarations have to be included in it
     *
     * @return void
     */
    private function exportDocumentViewRecursive($node, $stream, $skipBinary, $noRecurse, $root=false)
    {
        //TODO: encode name according to spec
        $nodename = $this->escapeXmlName($node->getName());
        fwrite($stream, "<$nodename");
        if ($root) {
            $this->exportNamespaceDeclarations($stream);
        }
        foreach ($node->getProperties() as $name => $property) {
            if ($property->isMultiple()) {
                // skip multiple properties. jackrabbit does this too. cheap but whatever. use system view for a complete export
                continue;
            }
            if (PropertyType::BINARY == $property->getType()) {
                if ($skipBinary) {
                    continue;
                }
                $value = base64_encode($property->getString());
            } else {
                $value = htmlspecialchars($property->getString());
            }
            fwrite($stream, ' '.$this->escapeXmlName($name).'="'.$value.'"');
        }
        if ($noRecurse || ! $node->hasNodes()) {
            fwrite($stream, '/>');
        } else {
            fwrite($stream, '>');
            foreach ($node as $child) {
                $this->exportDocumentViewRecursive($child, $stream, $skipBinary, $noRecurse);
            }
            fwrite($stream, "</$nodename>");
        }
    }
    private function escapeXmlName($name)
    {
        $name = preg_replace('/_(x[0-9a-fA-F]{4})/', '_x005f_\\1', $name);
        return str_replace(array(' ',       '<',       '>',       '"',       "'"),
                           array('_x0020_', '_x003c_', '_x003e_', '_x0022_', '_x0027_'),
                           $name); // TODO: more invalid characters?
    }
    private function exportNamespaceDeclarations($stream)
    {
        foreach ($this->workspace->getNamespaceRegistry() as $key => $uri) {
            if (! empty($key)) { // no ns declaration for empty namespace
                fwrite($stream, " xmlns:$key=\"$uri\"");
            }
        }
    }

    /**
     * Within the scope of this Session, this method maps uri to prefix. The
     * remapping only affects operations done through this Session. To clear
     * all remappings, the client must acquire a new Session.
     * All local mappings already present in the Session that include either
     * the specified prefix or the specified uri are removed and the new mapping
     * is added.
     *
     * @param string $prefix a string
     * @param string $uri a string
     * @return void
     *
     * @throws \PHPCR\NamespaceException if an attempt is made to map a namespace URI to a prefix beginning with the
     *                                   characters "xml" (in any combination of case) or if an attempt is made to map
     *                                   either the empty prefix or the empty namespace (i.e., if either $prefix or $uri
     *                                   are the empty string).
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function setNamespacePrefix($prefix, $uri)
    {
        $this->namespaceRegistry->checkPrefix($prefix);
        throw new NotImplementedException('TODO: implement session scope remapping of namespaces');
        //this will lead to rewrite all names and paths in requests and replies. part of this can be done in ObjectManager::normalizePath
    }

    /**
     * Returns all prefixes currently mapped to URIs in this Session.
     *
     * @return array a string array
     * @throws \PHPCR\RepositoryException if an error occurs
     * @api
     */
    public function getNamespacePrefixes()
    {
        //TODO: once setNamespacePrefix is implemented, must take session remaps into account
        return $this->namespaceRegistry->getPrefixes();
    }

    /**
     * Returns the URI to which the given prefix is mapped as currently set in
     * this Session.
     *
     * @param string $prefix a string
     * @return string a string
     * @throws \PHPCR\NamespaceException if the specified prefix is unknown.
     * @throws \PHPCR\RepositoryException if another error occurs
     * @api
     */
    public function getNamespaceURI($prefix)
    {
        //TODO: once setNamespacePrefix is implemented, must take session remaps into account
        return $this->namespaceRegistry->getURI($prefix);
    }

    /**
     * Returns the prefix to which the given uri is mapped as currently set in
     * this Session.
     *
     * @param string $uri a string
     * @return string a string
     * @throws \PHPCR\NamespaceException if the specified uri is unknown.
     * @throws \PHPCR\RepositoryException - if another error occurs
     * @api
     */
    public function getNamespacePrefix($uri)
    {
        //TODO: once setNamespacePrefix is implemented, must take session remaps into account
        return $this->namespaceRegistry->getPrefix($uri);
    }

    /**
     * Releases all resources associated with this Session. This method should
     * be called when a Session is no longer needed.
     *
     * @return void
     * @api
     */
    public function logout()
    {
        //TODO anything to do on logout?
        //OPTIMIZATION: flush object manager
        $this->logout = true;
        self::unregisterSession($this);
        $this->getTransport()->logout();
    }

    /**
     * Returns true if this Session object is usable by the client. Otherwise,
     * returns false.
     * A usable Session is one that is neither logged-out, timed-out nor in
     * any other way disconnected from the repository.
     *
     * @return boolean true if this Session is usable, false otherwise.
     * @api
     */
    public function isLive()
    {
        return ! $this->logout;
    }

    /**
     * Returns the access control manager for this Session.
     *
     * @return \PHPCR\Security\AccessControlManager the access control manager for this Session
     * @throws \PHPCR\UnsupportedRepositoryOperationException if access control is not supported.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getAccessControlManager()
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException();
    }

    /**
     * Returns the retention and hold manager for this Session.
     *
     * @return \PHPCR\Retention\RetentionManagerInterface the retention manager for this Session.
     * @throws \PHPCR\UnsupportedRepositoryOperationException if retention and hold are not supported.
     * @throws \PHPCR\RepositoryException if another error occurs.
     * @api
     */
    public function getRetentionManager()
    {
        throw new \PHPCR\UnsupportedRepositoryOperationException();
    }

    /**
     * Implementation specific: The object manager is also used by other components, i.e. the QueryManager.
     * DO NOT USE if you are a consumer of the api
     * @private
     */
    public function getObjectManager()
    {
        return $this->objectManager;
    }

    /**
     * Implementation specific: The transport implementation is also used by other components, i.e. the NamespaceRegistry
     * @private
     */
    public function getTransport()
    {
        return $this->objectManager->getTransport();
    }

    /**
     * Implementation specific: register session in session registry
     * @private
     */
    protected static function registerSession(Session $session)
    {
        $key = $session->getRegistryKey();
        self::$sessionRegistry[$key] = $session;
    }

    /**
     * Implementation specific: unregister session in session registry
     * @private
     */
    protected static function unregisterSession(Session $session)
    {
        $key = $session->getRegistryKey();
        unset(self::$sessionRegistry[$key]);
    }

    /**
     * Implementation specific: create an id for the session registry
     * @private
     * @return an id for this session
     */
    public function getRegistryKey()
    {
        return spl_object_hash($this);
    }

    /**
     * Implementation specific: get a session from the session registry
     *
     * @private
     * @param $key key for the session
     * @return the session or null if none is registered with the given key
     */
    public static function getSessionFromRegistry($key)
    {
        if (isset(self::$sessionRegistry[$key])) {
            return self::$sessionRegistry[$key];
        }
    }
}
